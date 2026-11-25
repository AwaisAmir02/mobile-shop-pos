<?php

namespace App\Models;

use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lump-sum payment taken from a customer at the counter (W1), and where
 * it went. The money itself is recorded on what it paid, through the same
 * actions as any other payment (sale payments, counter-service payments, an
 * udhaar repayment); this row is the customer's receipt for the whole amount.
 * It holds no balance of its own, so nothing can be counted twice.
 */
class CustomerReceipt extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'customer_id', 'user_id', 'amount', 'payment_method', 'shop_account_id', 'note', 'applied_to', 'receipt_date', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_to' => 'array',
            'receipt_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class)->withoutGlobalScopes();
    }

    public function number(): string
    {
        return 'RCPT-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function receivedIntoLabel(): string
    {
        return match ($this->payment_method) {
            SalePaymentMethod::Cash->value => 'Cash',
            SalePaymentMethod::Account->value => $this->shopAccount?->name ?? 'Shop account',
            default => 'Not recorded',
        };
    }

    /** The part kept as udhaar advance, beyond everything that was owed. */
    public function advanceAmount(): float
    {
        return (float) collect($this->applied_to)->where('source', 'advance')->sum('amount');
    }
}
