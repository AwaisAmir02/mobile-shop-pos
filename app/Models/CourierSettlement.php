<?php

namespace App\Models;

use App\Enums\CourierKey;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payout from a courier (O4, App\Actions\CourierSettlements): the cash it
 * collected on many parcels, less its delivery and return charges, the tax it
 * withheld and anything else it took off, paid into one account. Each parcel's
 * collected amount is a payment on its sale (SalePaymentMethod::Courier).
 */
class CourierSettlement extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'courier', 'reference', 'settlement_date', 'cod_total', 'charges_total', 'tax_withheld', 'other_deductions', 'net_amount', 'payment_method', 'shop_account_id', 'note', 'status', 'user_id', 'cancelled_at', 'cancelled_by'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'courier' => CourierKey::class,
            'settlement_date' => 'date',
            'cod_total' => 'decimal:2',
            'charges_total' => 'decimal:2',
            'tax_withheld' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourierSettlementItem::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function number(): string
    {
        return 'CS-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === RecordStatus::Cancelled;
    }

    /** Everything the courier kept: charges, tax withheld and other deductions. */
    public function deductions(): float
    {
        return round((float) $this->charges_total + (float) $this->tax_withheld + (float) $this->other_deductions, 2);
    }
}
