<?php

namespace App\Services\Billing;

use App\Enums\AddonBilling;
use App\Enums\BillingPhase;
use App\Enums\PlanType;
use App\Enums\ShopAddonStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionBillingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Trials, renewals and the lock for shops on automatic billing.
 *
 * Nothing here knows how money arrives: invoices go through
 * PaymentGateways::current() and are settled by
 * SubscriptionPaymentService::confirm(), so a real gateway replaces the
 * manual bank transfer without touching this class.
 *
 * State lives on the shop (subscription_status, subscription_start_date,
 * plan_type, trial_ends_at) and is read, never stored as a phase:
 * - Inactive + trial not over        → Trial
 * - Inactive + trial over            → TrialEnded (locked)
 * - Active, period ends > N days out → Active
 * - Active, within N days of the end → EndingSoon (renewal invoice offered)
 * - Active, period over              → Lapsed (locked)
 * A payment sent and waiting for review keeps a locked shop open until an
 * admin has checked it.
 *
 * Shops with auto_billing off (everything that existed before this) are
 * Manual: an admin runs them by hand from the shop page, as before.
 */
class BillingCycle
{
    public function __construct(
        protected SubscriptionBillingService $billing,
        protected PaymentGateways $gateways,
    ) {}

    public function enabled(Shop $shop): bool
    {
        return (bool) $shop->auto_billing && $shop->plan_id !== null;
    }

    public function phase(Shop $shop): BillingPhase
    {
        if (! $this->enabled($shop)) {
            return BillingPhase::Manual;
        }

        if ($shop->subscription_status !== SubscriptionStatus::Active) {
            return $shop->trial_ends_at && now()->lt($shop->trial_ends_at) ? BillingPhase::Trial : BillingPhase::TrialEnded;
        }

        $renewal = $this->renewsAt($shop);

        return match (true) {
            $renewal === null => BillingPhase::Active,
            now()->gte($renewal) => BillingPhase::Lapsed,
            now()->gte($renewal->copy()->subDays($this->noticeDays())) => BillingPhase::EndingSoon,
            default => BillingPhase::Active,
        };
    }

    public function noticeDays(): int
    {
        return (int) config('billing.renewal_notice_days', 2);
    }

    public function locked(Shop $shop): bool
    {
        if (! $this->phase($shop)->locks()) {
            return false;
        }

        return ! (SubscriptionPayment::openFor($shop->id)?->isAwaitingReview() ?? false);
    }

    /** The start of the day the current paid period ends on, i.e. when the next one starts. */
    public function renewsAt(Shop $shop): ?Carbon
    {
        return $shop->expectedRenewalDate()?->copy()->startOfDay();
    }

    /** The end of the trial or the paid period, whichever the shop is in. */
    public function endsAt(Shop $shop): ?Carbon
    {
        return match ($this->phase($shop)) {
            BillingPhase::Trial, BillingPhase::TrialEnded => $shop->trial_ends_at?->copy(),
            BillingPhase::Active, BillingPhase::EndingSoon, BillingPhase::Lapsed => $this->renewsAt($shop),
            BillingPhase::Manual => null,
        };
    }

    /** Whole days left, rounded up: 1 on the last day, 0 once it has ended. */
    public function daysLeft(Shop $shop): int
    {
        $end = $this->endsAt($shop);

        return $end ? max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400)) : 0;
    }

    /** @return array{auto_billing: bool, trial_ends_at: Carbon} for a shop signing up to $plan */
    public function signupAttributes(Plan $plan): array
    {
        return ['auto_billing' => true, 'trial_ends_at' => now()->addDays(max(0, (int) $plan->trial_days))];
    }

    /** The first paid period starts when the trial ends, so paying early never costs trial days; or today, once it has ended. */
    public function firstPeriodStart(Shop $shop): Carbon
    {
        $end = $shop->trial_ends_at;

        return ($end && $end->isFuture() ? $end->copy() : now())->startOfDay();
    }

    public function months(Shop $shop): int
    {
        return $shop->plan_type === PlanType::Yearly ? 12 : 1;
    }

    /**
     * The open payment for the shop's plan, creating its invoice once one is
     * due: the first payment (during or after the trial) or the renewal
     * (from the notice window on). Null while nothing is due.
     */
    public function openPayment(Shop $shop, ?int $userId = null): ?SubscriptionPayment
    {
        if ($open = SubscriptionPayment::openFor($shop->id)) {
            return $open;
        }

        if (! $this->paymentDue($this->phase($shop))) {
            return null;
        }

        return DB::transaction(function () use ($shop, $userId) {
            // Decided again from the locked row: a stale copy must never open a second invoice.
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $phase = $this->phase($shop);

            if (! $this->paymentDue($phase) || ($open = SubscriptionPayment::openFor($shop->id))) {
                return $open ?? null;
            }

            $first = $phase === BillingPhase::Trial || $phase === BillingPhase::TrialEnded;
            $invoice = $this->createInvoice($shop, $first ? $this->firstPeriodStart($shop) : $this->renewsAt($shop), $first ? 'first payment' : 'renewal', $userId);

            return $this->gateways->current()->start($invoice);
        });
    }

    protected function paymentDue(BillingPhase $phase): bool
    {
        return in_array($phase, [BillingPhase::Trial, BillingPhase::TrialEnded, BillingPhase::EndingSoon, BillingPhase::Lapsed], true);
    }

    /** A plan invoice for the period from $start: the plan, add-on charges not invoiced yet, and each recurring add-on. */
    public function createInvoice(Shop $shop, CarbonInterface $start, string $note, ?int $userId): SubscriptionInvoice
    {
        $start = Carbon::parse($start)->startOfDay();
        $months = $this->months($shop);
        $lines = $this->linesFor($shop, $start, $months);

        $invoice = $this->billing->create($shop, [
            'period_start' => $start->toDateString(),
            'months' => $months,
            'amount' => $this->total($lines),
            'notes' => $shop->plan->name.' plan — '.$note,
            'items' => $lines,
        ], $userId);

        $this->markInvoiced($lines, $invoice);

        return $invoice;
    }

    /** @return array<int, array{kind: string, label: string, amount: float, shop_addon_id?: int}> */
    public function linesFor(Shop $shop, CarbonInterface $start, int $months): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $lines = [$this->planLine($shop, $start, $months)];

        foreach ($this->notInvoiced($shop) as $shopAddon) {
            $lines[] = [
                'kind' => 'addon_part',
                'label' => $shopAddon->billing === AddonBilling::OneOff
                    ? $shopAddon->name.', one-off'
                    : $shopAddon->name.', '.$shopAddon->starts_at->format('d M').' – '.$start->copy()->subDay()->format('d M Y').' (part of a cycle)',
                'amount' => round((float) $shopAddon->first_charge, 2),
                'shop_addon_id' => $shopAddon->id,
            ];
        }

        foreach ($this->recurring($shop, $start) as $shopAddon) {
            $lines[] = $this->addonLine($shopAddon, $shop, $start, $months);
        }

        return $lines;
    }

    /**
     * What the shop owes, for Plan & Usage: the open invoice if there is one,
     * otherwise what the next renewal will be with what's been added since.
     *
     * @return array{lines: array<int, array>, total: float, due: ?Carbon, invoice: ?SubscriptionInvoice}|null
     */
    public function owed(Shop $shop): ?array
    {
        if (! $this->enabled($shop)) {
            return null;
        }

        $open = SubscriptionPayment::openFor($shop->id)?->invoice;

        if ($open) {
            return ['lines' => $open->lines(), 'total' => (float) $open->total, 'due' => $this->endsAt($shop), 'invoice' => $open];
        }

        $renewal = $this->renewsAt($shop);

        if ($shop->subscription_status !== SubscriptionStatus::Active || ! $renewal) {
            return null;
        }

        $lines = $this->linesFor($shop, $renewal, $this->months($shop));

        return ['lines' => $lines, 'total' => $this->total($lines), 'due' => $renewal, 'invoice' => null];
    }

    /**
     * What buying $addon now would cost, for the confirmation: `now` is the
     * charge for the rest of this cycle (nothing during the free trial, the
     * whole price for a one-off), `each` the charge every cycle after, and
     * `on` the date of the plan payment that collects them.
     *
     * @return array{now: float, each: ?float, on: ?Carbon, trial: bool}
     */
    public function quote(Shop $shop, Addon $addon): array
    {
        $trial = $shop->subscription_status !== SubscriptionStatus::Active;
        $period = $this->periodCharge($addon, $shop);
        $oneOff = $addon->billing === AddonBilling::OneOff;

        return [
            'now' => $trial ? ($oneOff ? $period : 0.0) : $this->proRated($shop, $period),
            'each' => $oneOff ? null : $period,
            'on' => $trial ? $this->firstPeriodStart($shop) : $this->renewsAt($shop),
            'trial' => $trial,
        ];
    }

    /**
     * Bills a plan-billed add-on bought just now. In a paid period it owes
     * the rest of the cycle, pro-rated; on the free trial a recurring one
     * owes nothing until the first payment and a one-off owes its price.
     * Anything owed goes on the open plan invoice if there is one, or waits
     * for the next.
     */
    public function chargeNewAddon(ShopAddon $shopAddon, Shop $shop): void
    {
        $open = SubscriptionPayment::openFor($shop->id)?->invoice;

        if ($open && (ShopAddon::where('subscription_invoice_id', $open->id)->exists() || $open->isPaid() || $open->isCancelled())) {
            $open = null;
        }

        $period = $this->periodCharge($shopAddon, $shop);
        $oneOff = $shopAddon->billing === AddonBilling::OneOff;
        $first = $shop->subscription_status === SubscriptionStatus::Active
            ? $this->proRated($shop, $period)
            : ($oneOff ? $period : 0.0);

        $lines = [];

        if ($first > 0) {
            $shopAddon->update(['first_charge' => $first, 'first_charge_invoice_id' => $open?->id]);

            if ($open) {
                $lines[] = [
                    'kind' => 'addon_part',
                    'label' => $oneOff ? $shopAddon->name.', one-off' : $shopAddon->name.', '.now()->format('d M').' – '.$open->period_start->copy()->subDay()->format('d M Y').' (part of a cycle)',
                    'amount' => $first,
                    'shop_addon_id' => $shopAddon->id,
                ];
            }
        }

        if ($open && ! $oneOff) {
            $lines[] = $this->addonLine($shopAddon, $shop, $open->period_start, $open->months);
        }

        if ($lines !== []) {
            $this->appendLines($open, $lines);
        }
    }

    /** When a one-off add-on bought now ends: with the period it was bought in, which on the trial is the first paid one. */
    public function oneOffEndsAt(Shop $shop): Carbon
    {
        if ($shop->subscription_status === SubscriptionStatus::Active && ($renewal = $this->renewsAt($shop))) {
            return $renewal;
        }

        return $this->firstPeriodStart($shop)->addMonthsNoOverflow($this->months($shop));
    }

    /**
     * Admin action: more trial days, counted from the current end or from
     * now if it has passed. An unpaid first invoice moves with it.
     */
    public function extendTrial(Shop $shop, int $days): Shop
    {
        if (! $this->enabled($shop)) {
            throw new RuntimeException('This shop isn’t on automatic billing, so it has no trial. Turn automatic billing on first.');
        }

        if ($shop->subscription_status === SubscriptionStatus::Active) {
            throw new RuntimeException('This shop has already paid, so it isn’t on a trial. Change its start date instead.');
        }

        return DB::transaction(function () use ($shop, $days) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $from = $shop->trial_ends_at && $shop->trial_ends_at->isFuture() ? $shop->trial_ends_at->copy() : now();
            $shop->update(['trial_ends_at' => $from->addDays($days)]);

            $invoice = SubscriptionPayment::openFor($shop->id)?->invoice;

            if ($invoice && ! $invoice->isPaid() && ! $invoice->isCancelled() && $invoice->plan_id === null && ! ShopAddon::where('subscription_invoice_id', $invoice->id)->exists()) {
                $this->reschedule($invoice, $this->firstPeriodStart($shop));
            }

            return $shop->fresh();
        });
    }

    /**
     * When a payment is confirmed after the day its period was due to
     * start (the shop was locked meanwhile), the period starts today
     * instead, so locked days aren't paid for. Returns the start.
     */
    public function startOnConfirm(SubscriptionInvoice $invoice): Carbon
    {
        $today = now()->startOfDay();

        if ($invoice->period_start->gte($today)) {
            return $invoice->period_start->copy();
        }

        $this->reschedule($invoice, $today);

        return $today;
    }

    /** One plan period of $addon: a yearly add-on on a monthly plan costs a twelfth. */
    public function periodCharge(Addon|ShopAddon $addon, Shop $shop): float
    {
        if ($addon->billing === AddonBilling::OneOff) {
            return round((float) $addon->price, 2);
        }

        return round((float) $addon->price * $this->months($shop) / $addon->billing->months(), 2);
    }

    /** $amount for the days left in the current paid period, counting today. */
    public function proRated(Shop $shop, float $amount): float
    {
        $renewal = $this->renewsAt($shop);
        $start = $shop->subscription_start_date?->copy()->startOfDay();

        if (! $renewal || ! $start || $renewal->lte($start)) {
            return round($amount, 2);
        }

        $from = now()->startOfDay()->max($start);
        $total = (int) round($start->diffInDays($renewal));
        $left = max(0, (int) round($from->diffInDays($renewal)));

        return round($amount * min($left, $total) / $total, 2);
    }

    /** @param array<int, array{amount: float|int|string}> $lines */
    public function total(array $lines): float
    {
        return round(array_sum(array_map(fn (array $line) => (float) $line['amount'], $lines)), 2);
    }

    protected function planLine(Shop $shop, Carbon $start, int $months): array
    {
        return [
            'kind' => 'plan',
            'label' => $shop->plan->name.' plan, '.$this->periodLabel($start, $months),
            'amount' => round((float) $shop->plan->price, 2),
        ];
    }

    protected function addonLine(ShopAddon $shopAddon, Shop $shop, CarbonInterface $start, int $months): array
    {
        return [
            'kind' => 'addon',
            'label' => $shopAddon->name.', '.$this->periodLabel(Carbon::parse($start), $months),
            'amount' => $this->periodCharge($shopAddon, $shop),
            'shop_addon_id' => $shopAddon->id,
        ];
    }

    protected function periodLabel(Carbon $start, int $months): string
    {
        return $start->format('d M').' – '.SubscriptionInvoice::periodEndFor($start, $months)->format('d M Y');
    }

    /** Pro-rated and one-off charges waiting for the next plan invoice. */
    protected function notInvoiced(Shop $shop)
    {
        return ShopAddon::where('shop_id', $shop->id)
            ->where('plan_billed', true)
            ->whereNotNull('first_charge')
            ->whereNull('first_charge_invoice_id')
            ->where('status', '!=', ShopAddonStatus::Cancelled->value)
            ->orderBy('id')
            ->get();
    }

    /** Plan-billed add-ons that renew with the plan and are still on for the period starting $start. */
    protected function recurring(Shop $shop, Carbon $start)
    {
        return ShopAddon::where('shop_id', $shop->id)
            ->where('plan_billed', true)
            ->where('status', ShopAddonStatus::Active->value)
            ->where('billing', '!=', AddonBilling::OneOff->value)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $start))
            ->orderBy('id')
            ->get();
    }

    protected function markInvoiced(array $lines, SubscriptionInvoice $invoice): void
    {
        $ids = collect($lines)->where('kind', 'addon_part')->pluck('shop_addon_id')->filter()->all();

        if ($ids !== []) {
            ShopAddon::whereIn('id', $ids)->update(['first_charge_invoice_id' => $invoice->id]);
        }
    }

    /** Adds lines to an unpaid invoice and its open payment, which then asks for the new total. */
    protected function appendLines(SubscriptionInvoice $invoice, array $lines): void
    {
        $items = array_merge($invoice->lines(), $lines);

        $this->billing->update($invoice, [
            'period_start' => $invoice->period_start->toDateString(),
            'months' => $invoice->months,
            'amount' => $this->total($items),
            'discount' => (float) $invoice->discount,
            'notes' => $invoice->notes,
        ]);

        $invoice->update(['items' => $items]);

        SubscriptionPayment::where('subscription_invoice_id', $invoice->id)->open()->update(['amount' => $invoice->fresh()->total]);
    }

    /** Moves an unpaid invoice's period to start on $start, keeping its lines' dates in step. */
    protected function reschedule(SubscriptionInvoice $invoice, Carbon $start): void
    {
        $this->billing->update($invoice, [
            'period_start' => $start->toDateString(),
            'months' => $invoice->months,
            'amount' => (float) $invoice->amount,
            'discount' => (float) $invoice->discount,
            'notes' => $invoice->notes,
        ]);

        if ($invoice->items) {
            $period = $this->periodLabel($start, $invoice->months);
            $invoice->update(['items' => array_map(
                fn (array $line) => in_array($line['kind'] ?? null, ['plan', 'addon'], true)
                    ? ['label' => preg_replace('/, [^,]*$/', ', '.$period, $line['label'])] + $line
                    : $line,
                $invoice->items,
            )]);
        }
    }
}
