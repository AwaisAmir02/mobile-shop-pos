<?php

namespace App\ReportQueries;

use App\Models\Employee;
use App\Support\StaffEarnings;
use Illuminate\Support\Collection;

/**
 * Commission by staff member for a period (the Staff Earnings screen): the
 * lines come from App\Support\StaffEarnings, the one calculation Payroll uses.
 */
class StaffEarningsReport
{
    private ?Collection $lines = null;

    public function __construct(private readonly int $shopId, private readonly string $from, private readonly string $to) {}

    public function lines(): Collection
    {
        return $this->lines ??= (new StaffEarnings)->lines($this->shopId, $this->from, $this->to);
    }

    /** @return Collection<int, array{employee: ?Employee, count: int, base: float, commission: float}> */
    public function byStaff(): Collection
    {
        $staff = Employee::withoutGlobalScopes()->where('shop_id', $this->shopId)->get()->keyBy('id');

        return $this->lines()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows, $id) => [
                'employee' => $staff[$id] ?? null,
                'count' => $rows->count(),
                'base' => round($rows->sum('base'), 2),
                'commission' => round($rows->sum('commission'), 2),
            ])
            ->sortByDesc('commission')
            ->values();
    }

    public function total(): float
    {
        return round($this->lines()->sum('commission'), 2);
    }
}
