<?php

namespace App\Models;

use App\Enums\AdvanceType;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money an employee owes back, so never an Expense. Never edited or deleted:
 * a wrong row is cancelled. The balance is always summed from these rows.
 */
class EmployeeAdvance extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'employee_id',
        'user_id',
        'payslip_id',
        'shop_account_id',
        'type',
        'amount',
        'transaction_date',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => AdvanceType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
