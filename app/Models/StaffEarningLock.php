<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The commission a payslip was generated with (App\Support\StaffEarnings).
 * It only counts once that payslip is paid; cancelling the payslip deletes it.
 */
class StaffEarningLock extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'employee_id', 'payslip_id', 'period', 'computed', 'adjustment'];

    protected function casts(): array
    {
        return ['period' => 'date', 'computed' => 'decimal:2', 'adjustment' => 'decimal:2'];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class)->withoutGlobalScopes();
    }
}
