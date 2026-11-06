<?php

namespace App\Models;

use App\Enums\SalaryType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\OptionalBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone the shop pays, whether or not they log in. A login is an optional
 * link to a User; roles stay about screen access and have nothing to do with
 * pay. No branch means they work across the shop.
 */
class Employee extends Model
{
    use BelongsToShop, BelongsToBranch, OptionalBranch;

    protected $fillable = [
        'shop_id',
        'user_id',
        'name',
        'phone',
        'designation',
        'salary_type',
        'rate',
        'joined_on',
        'is_active',
        'notes',
    ];

    protected $attributes = ['salary_type' => 'fixed', 'is_active' => true];

    public static function bootBelongsToBranch(): void {}

    protected function casts(): array
    {
        return [
            'salary_type' => SalaryType::class,
            'rate' => 'decimal:2',
            'joined_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** What this person currently owes the shop. */
    public function advanceBalance(): float
    {
        return round($this->advances()->get()->sum(fn (EmployeeAdvance $row) => $row->type->sign() * (float) $row->amount), 2);
    }

    /** Anyone with pay or advance history stays on record, even if it was cancelled. */
    public function hasPayrollHistory(): bool
    {
        return Payslip::withoutGlobalScopes()->where('employee_id', $this->id)->exists()
            || EmployeeAdvance::withoutGlobalScopes()->where('employee_id', $this->id)->exists();
    }
}
