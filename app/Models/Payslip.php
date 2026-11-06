<?php

namespace App\Models;

use App\Enums\PayslipStatus;
use App\Enums\SalaryType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use App\Models\Concerns\OptionalBranch;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One person's pay for one month. Rate, name and branch are copied on at
 * generation, so later changes to the employee never rewrite history.
 */
class Payslip extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation, OptionalBranch;

    protected $fillable = [
        'shop_id',
        'employee_id',
        'user_id',
        'period',
        'employee_name',
        'designation',
        'salary_type',
        'rate',
        'days',
        'base_pay',
        'extra_earnings',
        'cost_deductions',
        'held_deductions',
        'advance_recovery',
        'salary_cost',
        'net_pay',
        'status',
        'active_marker',
        'paid_on',
        'paid_by',
        'shop_account_id',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'draft', 'active_marker' => 1];

    public static function bootBelongsToBranch(): void {}

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'salary_type' => SalaryType::class,
            'rate' => 'decimal:2',
            'days' => 'decimal:2',
            'base_pay' => 'decimal:2',
            'extra_earnings' => 'decimal:2',
            'cost_deductions' => 'decimal:2',
            'held_deductions' => 'decimal:2',
            'advance_recovery' => 'decimal:2',
            'salary_cost' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'status' => PayslipStatus::class,
            'paid_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    public function number(): string
    {
        return 'PS-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === PayslipStatus::Cancelled;
    }

    public function isDraft(): bool
    {
        return $this->status === PayslipStatus::Draft;
    }

    public function isPaid(): bool
    {
        return $this->status === PayslipStatus::Paid;
    }

    public function grossEarnings(): float
    {
        return round((float) $this->base_pay + (float) $this->extra_earnings, 2);
    }

    /**
     * The one place the figures are worked out, from the rate, days, lines and
     * advance recovery. Salary cost is what the shop's books carry: an advance
     * being recovered doesn't lower it, because the advance was never a cost.
     * Net pay is the cash that changes hands.
     *
     * @param  iterable<PayslipLine>  $lines
     */
    public function recalculate(iterable $lines): void
    {
        $lines = collect($lines);

        $base = $this->salary_type === SalaryType::Daily ? Quantity::money($this->rate, $this->days ?? 0) : round((float) $this->rate, 2);
        $extras = round($lines->where('kind', PayslipLine::EARNING)->sum(fn ($line) => (float) $line->amount), 2);
        $deductions = $lines->where('kind', PayslipLine::DEDUCTION);
        $reducing = round($deductions->filter(fn ($line) => $line->reduces_cost)->sum(fn ($line) => (float) $line->amount), 2);
        $held = round($deductions->reject(fn ($line) => $line->reduces_cost)->sum(fn ($line) => (float) $line->amount), 2);

        $this->base_pay = $base;
        $this->extra_earnings = $extras;
        $this->cost_deductions = $reducing;
        $this->held_deductions = $held;
        $this->salary_cost = round($base + $extras - $reducing, 2);
        $this->net_pay = round($base + $extras - $reducing - $held - (float) $this->advance_recovery, 2);
    }
}
