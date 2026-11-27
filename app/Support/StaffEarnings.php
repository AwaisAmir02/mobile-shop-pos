<?php

namespace App\Support;

use App\Actions\SaleReturns;
use App\Enums\CommissionKind;
use App\Enums\CommissionTarget;
use App\Enums\PayslipStatus;
use App\Enums\SaleLineKind;
use App\Enums\ServiceTradeTool;
use App\Models\Membership;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\StaffCommissionRule;
use App\Models\StaffEarningLock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What staff earned in commission: the one calculation the earnings report,
 * the customer's visit history and Payroll all ask (StockAvailability's
 * pattern: worked out, never stored).
 *
 * A line counts for whoever it says performed it (sale_items.performed_by),
 * on a sale that isn't cancelled, in the month of the sale. Its base is what
 * it really brought in: its share of the bill after line and bill discounts,
 * less anything returned. A session taken from a package is worth the
 * package's price over its sessions. Products count only under a "Products
 * sold" rule.
 *
 * Which rule: a rule for that staff member beats a shop-wide one; among
 * those, the most specific target wins (one service, then its category, then
 * all services). Percent is of the base; flat is per one done.
 *
 * Payroll: generating a payslip fixes the month's commission on it (a
 * StaffEarningLock) and pre-fills a Commission line. Once that payslip is
 * paid, any later change to those months (a return, a cancelled bill, a
 * late-entered sale) shows up as the difference and is carried into the
 * next payslip's commission line, never by editing a paid one.
 */
class StaffEarnings
{
    /** @var array<int, Collection<int, StaffCommissionRule>> */
    protected array $rules = [];

    /** @var array<int, ?int> service item id => category id */
    protected array $categories = [];

    /**
     * Every commission-earning line in a period, for one staff member or all.
     *
     * @return Collection<int, array{sale_id: int, invoice: string, date: string, employee_id: int, name: string, kind: SaleLineKind, base: float, rule: ?string, commission: float}>
     */
    public function lines(int $shopId, string $from, string $to, ?int $employeeId = null): Collection
    {
        $start = CarbonImmutable::parse($from, 'Asia/Karachi')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Karachi')->endOfDay()->utc();

        $saleIds = SaleItem::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.shop_id', $shopId)
            ->where('sales.status', '!=', 'cancelled')
            ->whereBetween('sales.created_at', [$start, $end])
            ->whereNotNull('sale_items.performed_by')
            ->when($employeeId, fn ($q) => $q->where('sale_items.performed_by', $employeeId))
            ->distinct()
            ->pluck('sale_items.sale_id');

        if ($saleIds->isEmpty()) {
            return collect();
        }

        $sales = Sale::withoutGlobalScopes()->whereIn('id', $saleIds)->get()->keyBy('id');
        $items = SaleItem::withoutGlobalScopes()->whereIn('sale_id', $saleIds)->orderBy('id')->get();
        $returned = $this->returned($items->pluck('id')->all());
        $memberships = Membership::withoutGlobalScopes()->whereIn('id', $items->pluck('membership_id')->filter()->unique())->get()->keyBy('id');
        $rows = collect();

        foreach ($items->groupBy('sale_id') as $saleId => $saleItems) {
            $sale = $sales[$saleId];
            $values = SaleReturns::rowValues($sale, $saleItems);

            foreach ($saleItems as $item) {
                if ($item->performed_by === null || ($employeeId !== null && (int) $item->performed_by !== $employeeId)) {
                    continue;
                }

                $kind = $item->line_kind ?? SaleLineKind::Product;
                $quantity = Quantity::subtract($item->quantity, $returned[$item->id]['quantity'] ?? 0);
                $base = $kind === SaleLineKind::Package
                    ? round((float) ($memberships[$item->membership_id] ?? null)?->sessionValue() * (float) $quantity, 2)
                    : round(max(0.0, $values[$item->id] - ($returned[$item->id]['value'] ?? 0.0)), 2);
                $rule = $this->ruleFor($shopId, (int) $item->performed_by, $item);
                $commission = 0.0;

                if ($rule !== null && Quantity::compare($quantity, 0) > 0) {
                    $commission = $rule->kind === CommissionKind::Percent
                        ? round($base * (float) $rule->value / 100, 2)
                        : round((float) $rule->value * (float) $quantity, 2);
                }

                $rows->push([
                    'sale_id' => (int) $saleId,
                    'invoice' => $sale->invoiceNumber(),
                    'date' => $sale->created_at->copy()->timezone('Asia/Karachi')->toDateString(),
                    'employee_id' => (int) $item->performed_by,
                    'name' => $item->product_name,
                    'kind' => $kind,
                    'base' => $base,
                    'rule' => $rule?->rateLabel(),
                    'commission' => $commission,
                ]);
            }
        }

        return $rows->sortBy(fn (array $row) => $row['date'].'|'.str_pad((string) $row['sale_id'], 12, '0', STR_PAD_LEFT))->values();
    }

    /** A staff member's commission for a month (any day in it). */
    public function forMonth(int $shopId, int $employeeId, string $month): float
    {
        $first = Carbon::parse($month)->startOfMonth();

        return round($this->lines($shopId, $first->toDateString(), $first->copy()->endOfMonth()->toDateString(), $employeeId)->sum('commission'), 2);
    }

    /**
     * What changed in months already paid: their commission worked out now,
     * less what their payslips were made with, less adjustments already paid.
     */
    public function adjustmentDue(int $shopId, int $employeeId): float
    {
        $locks = StaffEarningLock::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('employee_id', $employeeId)
            ->whereHas('payslip', fn ($q) => $q->where('status', PayslipStatus::Paid->value))
            ->get();

        $due = 0.0;

        foreach ($locks as $lock) {
            $due += $this->forMonth($shopId, $employeeId, $lock->period->toDateString()) - (float) $lock->computed - (float) $lock->adjustment;
        }

        return round($due, 2);
    }

    /**
     * Called by Payroll::generate for a shop that pays commission: fixes the
     * month's figure on the payslip and adds the Commission line. A negative
     * adjustment can only take the line down to nothing; the rest waits.
     */
    public static function prefill(Payslip $payslip): void
    {
        $shop = Shop::find($payslip->shop_id);

        if ($shop === null || ! $shop->usesTool(ServiceTradeTool::StaffEarnings)) {
            return;
        }

        $earnings = new self;
        $computed = $earnings->forMonth($payslip->shop_id, $payslip->employee_id, $payslip->period->toDateString());
        $adjustment = $earnings->adjustmentDue($payslip->shop_id, $payslip->employee_id);
        $amount = round($computed + $adjustment, 2);
        $applied = $amount > 0 ? $adjustment : round(-$computed, 2);

        StaffEarningLock::create(['shop_id' => $payslip->shop_id, 'employee_id' => $payslip->employee_id, 'payslip_id' => $payslip->id, 'period' => $payslip->period->toDateString(), 'computed' => $computed, 'adjustment' => $applied]);

        if ($amount > 0) {
            $label = 'Commission '.$payslip->period->format('M Y').(round($adjustment, 2) != 0.0 ? ' (incl. '.($adjustment > 0 ? '+' : '−').'Rs '.number_format(abs($adjustment), 2).' from earlier months)' : '');
            $payslip->lines()->create(['shop_id' => $payslip->shop_id, 'kind' => PayslipLine::EARNING, 'label' => mb_substr($label, 0, 100), 'amount' => $amount, 'reduces_cost' => false]);
        }
    }

    /** Payroll::cancel: the month is open again and is fixed afresh by its next payslip. */
    public static function release(Payslip $payslip): void
    {
        StaffEarningLock::withoutGlobalScopes()->where('payslip_id', $payslip->id)->delete();
    }

    /** The rule that pays on this line for this person, or null. */
    public function ruleFor(int $shopId, int $employeeId, SaleItem $item): ?StaffCommissionRule
    {
        $rules = $this->rules[$shopId] ??= StaffCommissionRule::withoutGlobalScopes()->where('shop_id', $shopId)->where('is_active', true)->get();
        $kind = $item->line_kind ?? SaleLineKind::Product;
        $category = $item->service_item_id !== null ? $this->categoryOf($item->service_item_id) : null;

        $matches = $rules->filter(fn (StaffCommissionRule $rule) => ($rule->employee_id === null || $rule->employee_id === $employeeId) && match ($rule->target) {
            CommissionTarget::Service => in_array($kind, [SaleLineKind::Service, SaleLineKind::Package], true) && $rule->target_id === $item->service_item_id,
            CommissionTarget::ServiceCategory => in_array($kind, [SaleLineKind::Service, SaleLineKind::Package], true) && $category !== null && $rule->target_id === $category,
            CommissionTarget::AllServices => in_array($kind, [SaleLineKind::Service, SaleLineKind::Package], true),
            CommissionTarget::MembershipPlan => $kind === SaleLineKind::Membership && $rule->target_id === $item->membership_plan_id,
            CommissionTarget::AllMemberships => $kind === SaleLineKind::Membership,
            CommissionTarget::Products => $kind === SaleLineKind::Product,
        });

        return $matches->sortByDesc(fn (StaffCommissionRule $rule) => ($rule->employee_id !== null ? 10 : 0) + $rule->target->specificity())->first();
    }

    protected function categoryOf(int $serviceItemId): ?int
    {
        if (! array_key_exists($serviceItemId, $this->categories)) {
            $this->categories[$serviceItemId] = ServiceItem::withoutGlobalScopes()->whereKey($serviceItemId)->value('service_category_id');
        }

        return $this->categories[$serviceItemId];
    }

    /** @return array<int, array{quantity: int|float, value: float}> taken back by active returns, per sale item */
    protected function returned(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return SaleReturnItem::withoutGlobalScopes()
            ->whereIn('sale_item_id', $itemIds)
            ->whereIn('sale_return_id', SaleReturn::withoutGlobalScopes()->where('status', 'active')->select('id'))
            ->get(['sale_item_id', 'quantity', 'value'])
            ->groupBy('sale_item_id')
            ->map(fn (Collection $rows) => ['quantity' => Quantity::normalize($rows->sum(fn ($r) => (float) $r->quantity)), 'value' => round($rows->sum(fn ($r) => (float) $r->value), 2)])
            ->all();
    }
}
