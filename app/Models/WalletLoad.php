<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletLoad extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'provider',
        'account_name',
        'account_number',
        'shop_account_id',
        'amount',
        'fee',
        'discount',
        'total',
        'payment_status',
        'amount_paid',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'amount_paid' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
