<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a customer paid ahead on a booking or a job card (App\Actions\CustomerDeposits).
 * Held for them until it is used on a bill (a "deposit" sale payment),
 * refunded, or kept by the shop (a no-show). What is left is worked out, never
 * stored, so cancelling the bill that used it gives it back on its own.
 */
class CustomerDeposit extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'customer_id', 'appointment_id', 'job_card_id', 'amount', 'received_on', 'payment_method', 'shop_account_id', 'refunded', 'refunded_on', 'refund_method', 'refund_account_id', 'forfeited', 'forfeited_on', 'note', 'status', 'user_id', 'cancelled_at'];

    protected $attributes = ['status' => 'active', 'refunded' => 0, 'forfeited' => 0];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded' => 'decimal:2',
            'forfeited' => 'decimal:2',
            'received_on' => 'date',
            'refunded_on' => 'date',
            'forfeited_on' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function number(): string
    {
        return 'DP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** Used on bills that still stand. */
    public function used(): float
    {
        return round((float) SalePayment::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.customer_deposit_id', $this->id)
            ->where('sale_payments.payment_method', SalePaymentMethod::Deposit->value)
            ->where('sales.status', '!=', 'cancelled')
            ->sum('sale_payments.amount'), 2);
    }

    public function remaining(): float
    {
        if ($this->status === RecordStatus::Cancelled) {
            return 0.0;
        }

        return round(max(0.0, (float) $this->amount - $this->used() - (float) $this->refunded - (float) $this->forfeited), 2);
    }
}
