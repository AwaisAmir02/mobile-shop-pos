<?php

namespace App\Actions;

use App\Enums\MembershipKind;
use App\Enums\MemberStanding;
use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipFreeze;
use App\Models\MembershipPlan;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memberships (by time, a gym month) and packages (by sessions, 10 facials),
 * shared by salons, spas, gyms and trainers. One is only ever made by selling
 * it on a bill (ServiceLines -> fromSaleItem), so it is paid like anything
 * else on that bill: in full, part or on credit, which shows in the
 * customer's dues as usual. Cancelling the bill cancels it.
 *
 * Revenue is taken on the bill, the day it is sold (the shop's own books
 * work on cash). Spreading it over the months it covers is a choice for the
 * accountant; nothing here would need to change for it.
 */
class Memberships
{
    public static function fromSaleItem(Sale $sale, SaleItem $item, CarbonInterface $saleDate): Membership
    {
        $plan = MembershipPlan::withoutGlobalScopes()->findOrFail($item->membership_plan_id);
        $starts = self::startFor((int) $sale->customer_id, $plan, $saleDate);

        $membership = new Membership([
            'customer_id' => $sale->customer_id,
            'membership_plan_id' => $plan->id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'plan_name' => $plan->name,
            'kind' => $plan->kind,
            'price' => $item->line_total,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $plan->endsOnFor($starts),
            'sessions_total' => $plan->kind === MembershipKind::Sessions ? $plan->sessions : null,
            'service_item_ids' => $plan->service_item_ids,
            'max_freeze_days' => $plan->max_freeze_days,
        ]);
        $membership->shop_id = $sale->shop_id;
        $membership->branch_id = $sale->branch_id;
        $membership->save();

        return $membership;
    }

    /**
     * A renewal carries on from the day after the current one ends, so paying
     * a few days early doesn't lose those days; otherwise it starts on the bill's day.
     */
    public static function startFor(int $customerId, MembershipPlan $plan, CarbonInterface $saleDate): CarbonInterface
    {
        if ($plan->kind !== MembershipKind::Time) {
            return $saleDate->copy()->startOfDay();
        }

        $latestEnd = Membership::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('kind', MembershipKind::Time->value)
            ->where('status', RecordStatus::Active->value)
            ->whereNotNull('ends_on')
            ->where('ends_on', '>=', $saleDate->toDateString())
            ->max('ends_on');

        return $latestEnd !== null ? Carbon::parse($latestEnd)->addDay()->startOfDay() : $saleDate->copy()->startOfDay();
    }

    /** Pauses a membership for these days; its end moves out by as many days. */
    public static function freeze(Membership $membership, string $from, string $to, ?string $reason, ?int $userId): MembershipFreeze
    {
        return DB::transaction(function () use ($membership, $from, $to, $reason, $userId) {
            $membership = Membership::withoutGlobalScopes()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->startOfDay();

            if ($membership->isCancelled() || $membership->kind !== MembershipKind::Time || $membership->ends_on === null) {
                throw ValidationException::withMessages(['freeze' => 'Only a running membership can be frozen.']);
            }

            if ($toDate->lt($fromDate)) {
                throw ValidationException::withMessages(['freeze' => 'The freeze must end on or after the day it starts.']);
            }

            if ($fromDate->lt($membership->starts_on) || $fromDate->gt($membership->ends_on)) {
                throw ValidationException::withMessages(['freeze' => 'A freeze has to start while the membership is running ('.$membership->starts_on->format('d M').' to '.$membership->ends_on->format('d M Y').').']);
            }

            $days = (int) $fromDate->diffInDays($toDate) + 1;
            $already = $membership->daysFrozen();

            if ($membership->max_freeze_days <= 0) {
                throw ValidationException::withMessages(['freeze' => 'The '.$membership->plan_name.' can\'t be frozen. Allow freezing on the membership first.']);
            }

            if ($already + $days > $membership->max_freeze_days) {
                throw ValidationException::withMessages(['freeze' => 'The '.$membership->plan_name.' allows '.$membership->max_freeze_days.' frozen days; '.$already.' are used.']);
            }

            $overlaps = $membership->freezes->contains(fn (MembershipFreeze $f) => $f->from_date->lte($toDate) && $f->to_date->gte($fromDate));

            if ($overlaps) {
                throw ValidationException::withMessages(['freeze' => 'Those days are already frozen.']);
            }

            $freeze = MembershipFreeze::create(['shop_id' => $membership->shop_id, 'membership_id' => $membership->id, 'from_date' => $fromDate->toDateString(), 'to_date' => $toDate->toDateString(), 'reason' => $reason, 'user_id' => $userId]);
            $membership->update(['ends_on' => $membership->ends_on->copy()->addDays($days)->toDateString()]);

            return $freeze;
        });
    }

    /** Takes a freeze back off: the end moves in again. */
    public static function unfreeze(MembershipFreeze $freeze): void
    {
        DB::transaction(function () use ($freeze) {
            $freeze = MembershipFreeze::withoutGlobalScopes()->whereKey($freeze->id)->lockForUpdate()->firstOrFail();

            if ($freeze->status === RecordStatus::Cancelled) {
                return;
            }

            $membership = Membership::withoutGlobalScopes()->whereKey($freeze->membership_id)->lockForUpdate()->firstOrFail();
            $freeze->update(['status' => RecordStatus::Cancelled]);
            $membership->update(['ends_on' => $membership->ends_on->copy()->subDays($freeze->days())->toDateString()]);
        });
    }

    /**
     * Where a customer stands on a day, and the membership that decides it:
     * a running one that day if any (frozen counts as running, but says so),
     * otherwise the one that ended last.
     *
     * @return array{standing: MemberStanding, membership: ?Membership}
     */
    public static function standing(Customer $customer, ?string $date = null): array
    {
        $date ??= now('Asia/Karachi')->toDateString();
        $memberships = self::forCustomer($customer->id);

        foreach ($memberships as $membership) {
            if ($membership->starts_on->toDateString() > $date) {
                continue;
            }

            $frozen = $membership->freezes->first(fn (MembershipFreeze $f) => $f->from_date->toDateString() <= $date && $f->to_date->toDateString() >= $date);

            if ($membership->ends_on !== null && $membership->ends_on->toDateString() < $date) {
                continue;
            }

            if ($membership->kind === MembershipKind::Sessions && $membership->sessionsLeft() <= 0) {
                continue;
            }

            return ['standing' => $frozen !== null ? MemberStanding::Frozen : MemberStanding::Active, 'membership' => $membership];
        }

        $last = $memberships->first();

        if ($last === null) {
            return ['standing' => MemberStanding::None, 'membership' => null];
        }

        $usedUp = $last->kind === MembershipKind::Sessions && $last->sessionsLeft() <= 0 && ($last->ends_on === null || $last->ends_on->toDateString() >= $date);

        return ['standing' => $usedUp ? MemberStanding::UsedUp : MemberStanding::Expired, 'membership' => $last];
    }

    /** @return Collection<int, Membership> active ones, latest ending first */
    public static function forCustomer(int $customerId): Collection
    {
        return Membership::withoutGlobalScopes()
            ->with('freezes')
            ->where('customer_id', $customerId)
            ->where('status', RecordStatus::Active->value)
            ->orderByRaw('ends_on IS NULL DESC')
            ->orderByDesc('ends_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Packages with sessions left that cover a service, for the register's "Use package".
     *
     * @return Collection<int, Membership>
     */
    public static function usablePackages(int $customerId, ?int $serviceItemId, string $date): Collection
    {
        return Membership::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('kind', MembershipKind::Sessions->value)
            ->where('status', RecordStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->orderBy('id')
            ->get()
            ->filter(fn (Membership $m) => $m->covers($serviceItemId) && $m->sessionsLeft() > 0)
            ->values();
    }

    /** The next free member number in a shop: the PIN a fingerprint device knows them by. */
    public static function assignMemberNo(Customer $customer): int
    {
        return DB::transaction(function () use ($customer) {
            $customer = Customer::withoutGlobalScopes()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($customer->member_no !== null) {
                return (int) $customer->member_no;
            }

            // One number range with staff attendance PINs: a device knows both by PIN.
            $next = BiometricDevices::nextPin($customer->shop_id);
            $customer->forceFill(['member_no' => $next])->save();

            return $next;
        });
    }
}
