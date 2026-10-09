<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\WalletLoadDirection;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletLoad extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'user_id',
        'customer_id',
        'direction',
        'provider',
        'account_name',
        'account_number',
        'shop_account_id',
        'amount',
        'fee',
        'discount',
        'fee_included_in_amount',
        'net_amount',
        'total',
        'payment_status',
        'amount_paid',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'direction' => WalletLoadDirection::class,
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'fee_included_in_amount' => 'boolean',
            'net_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'amount_paid' => 'decimal:2',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    public function receiptNumber(): string
    {
        return 'WL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function amountOwed(): float
    {
        if ($this->payment_status === PaymentStatus::Paid) {
            return 0.0;
        }

        return max(0.0, (float) $this->total - (float) $this->amount_paid);
    }
}
