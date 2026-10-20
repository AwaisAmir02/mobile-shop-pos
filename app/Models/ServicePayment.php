<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePayment extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'user_id',
        'customer_id',
        'service_category_id',
        'service_item_id',
        'amount',
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
            'amount' => 'decimal:2',
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

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(ServiceItem::class);
    }

    public function receiptNumber(): string
    {
        return 'SV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function amountOwed(): float
    {
        if ($this->payment_status === PaymentStatus::Paid) {
            return 0.0;
        }

        return max(0.0, (float) $this->amount - (float) $this->amount_paid);
    }

    /**
     * No stored total — amount is the only charge here (no fee/discount).
     * Exposed as a read-only "total" accessor purely so RecordModulePayment
     * (shared with Wallet Load, Balance Load, Bill Payment, Repair, NADRA
     * Verification, SIM Sale) can treat this record the same shape as theirs
     * without a redundant, always-equal column.
     */
    protected function total(): Attribute
    {
        return Attribute::make(get: fn () => (float) $this->amount);
    }
}
