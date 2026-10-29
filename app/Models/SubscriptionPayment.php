<?php

namespace App\Models;

use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'shop_id',
        'subscription_invoice_id',
        'gateway',
        'status',
        'amount',
        'reference',
        'proof_path',
        'submitted_at',
        'gateway_reference',
        'meta',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionPaymentStatus::class,
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    public function shopAddon(): HasOne
    {
        return $this->hasOne(ShopAddon::class, 'subscription_invoice_id', 'subscription_invoice_id');
    }

    public function purpose(): string
    {
        return $this->shopAddon ? 'Add-on: '.$this->shopAddon->name : 'Plan subscription';
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [SubscriptionPaymentStatus::Pending->value, SubscriptionPaymentStatus::Rejected->value]);
    }

    public static function openFor(int $shopId): ?self
    {
        return self::where('shop_id', $shopId)->open()->latest('id')->first();
    }

    public function isPending(): bool
    {
        return $this->status === SubscriptionPaymentStatus::Pending;
    }

    public function isAwaitingReview(): bool
    {
        return $this->isPending() && $this->submitted_at !== null;
    }
}
