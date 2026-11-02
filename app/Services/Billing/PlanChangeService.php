<?php

namespace App\Services\Billing;

use App\Enums\LimitKey;
use App\Enums\PlanType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionBillingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An owner switching their shop to another plan. Asking for it creates an
 * invoice for the new plan's full price (no pro-rating) through the normal
 * payment flow; nothing about the shop changes until that payment is
 * confirmed, when apply() moves the shop onto the plan and starts a fresh
 * period from the confirmation day.
 */
class PlanChangeService
{
    public function __construct(
        protected SubscriptionBillingService $billing,
        protected PaymentGateways $gateways,
        protected Entitlements $entitlements,
    ) {}

    /**
     * Every hard limit the shop is already over on $plan, with what has to
     * go before switching. Sales are never blocked, so they never block a
     * switch either; monthly limits compare against this month's usage.
     *
     * @return array<int, array{key: LimitKey, used: int, limit: int, reduceBy: int}>
     */
    public function blockers(Shop $shop, Plan $plan): array
    {
        return collect(LimitKey::cases())
            ->filter(fn (LimitKey $key) => $key->blocksAtLimit())
            ->map(function (LimitKey $key) use ($shop, $plan) {
                $limit = $this->entitlements->limitOn($shop, $plan, $key);
                $used = $this->entitlements->usage($shop, $key);

                return $limit !== null && $used > $limit
                    ? ['key' => $key, 'used' => $used, 'limit' => $limit, 'reduceBy' => $used - $limit]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** "Archive 120 products and 2 branches first." — or null when nothing blocks. */
    public function blockerMessage(Shop $shop, Plan $plan): ?string
    {
        $blockers = $this->blockers($shop, $plan);

        if ($blockers === []) {
            return null;
        }

        $parts = collect($blockers)->map(fn (array $b) => number_format($b['reduceBy']).' '.$b['key']->unit($b['reduceBy']).($b['key']->isMonthly() ? ' this month' : ''));

        return $plan->name.' allows fewer than you use now. Before switching, reduce: '.$parts->implode(', ').'.';
    }

    public function request(Shop $shop, Plan $plan, ?int $userId): SubscriptionPayment
    {
        if (! $this->entitlements->governed($shop)) {
            throw new RuntimeException('Your shop’s plan is set up by HisabOKitab. Contact us to change it.');
        }

        if (! $plan->is_active) {
            throw new RuntimeException('This plan is no longer available.');
        }

        if ($plan->id === $shop->plan_id) {
            throw new RuntimeException('Your shop is already on '.$plan->name.'.');
        }

        if (SubscriptionPayment::openFor($shop->id)) {
            throw new RuntimeException('Please finish your pending payment first, then change your plan.');
        }

        if ($message = $this->blockerMessage($shop, $plan)) {
            throw new RuntimeException($message);
        }

        return DB::transaction(function () use ($shop, $plan, $userId) {
            $invoice = $this->billing->create($shop, [
                'period_start' => now()->toDateString(),
                'months' => $this->monthsFor($plan),
                'amount' => (float) $plan->price,
                'notes' => 'Plan change: '.($shop->plan?->name ?? 'no plan').' → '.$plan->name,
                'plan_id' => $plan->id,
                'plan_type' => $plan->billing_interval->value,
            ], $userId);

            return $this->gateways->current()->start($invoice);
        });
    }

    /**
     * Runs inside SubscriptionPaymentService::confirm(). The owner has paid,
     * so the switch always happens — even if usage grew past the new plan's
     * limits while the payment waited; the shop then simply shows as over,
     * like any shop whose limits were lowered. The period starts today.
     */
    public function apply(SubscriptionInvoice $invoice): void
    {
        $plan = Plan::findOrFail($invoice->plan_id);
        $shop = Shop::whereKey($invoice->shop_id)->lockForUpdate()->firstOrFail();
        $today = now()->toDateString();

        if ($invoice->period_start->toDateString() !== $today) {
            $this->billing->update($invoice, [
                'period_start' => $today,
                'months' => $invoice->months,
                'amount' => (float) $invoice->amount,
                'discount' => (float) $invoice->discount,
                'notes' => $invoice->notes,
            ]);
        }

        $shop->update([
            'plan_id' => $plan->id,
            'plan_type' => $plan->billing_interval,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_start_date' => $today,
        ]);

        $this->entitlements->syncFeatureFlags($shop->fresh());
        $this->entitlements->forgetMeters($shop);
    }

    protected function monthsFor(Plan $plan): int
    {
        return $plan->billing_interval === PlanType::Yearly ? 12 : 1;
    }
}
