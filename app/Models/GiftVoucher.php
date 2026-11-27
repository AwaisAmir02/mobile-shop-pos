<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gift voucher sold for money now and spent by whoever brings the code
 * (App\Actions\GiftVouchers). What is left is worked out from the "gift_voucher"
 * payments on bills that still stand, never stored.
 */
class GiftVoucher extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'code', 'amount', 'buyer_customer_id', 'buyer_name', 'recipient_name', 'sold_on', 'expires_on', 'payment_method', 'shop_account_id', 'note', 'status', 'user_id', 'cancelled_at'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sold_on' => 'date',
            'expires_on' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_customer_id');
    }

    public function used(): float
    {
        return round((float) SalePayment::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.gift_voucher_id', $this->id)
            ->where('sale_payments.payment_method', SalePaymentMethod::GiftVoucher->value)
            ->where('sales.status', '!=', 'cancelled')
            ->sum('sale_payments.amount'), 2);
    }

    public function remaining(): float
    {
        return $this->status === RecordStatus::Cancelled ? 0.0 : round(max(0.0, (float) $this->amount - $this->used()), 2);
    }

    public function isExpiredOn(string $date): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < $date;
    }
}
