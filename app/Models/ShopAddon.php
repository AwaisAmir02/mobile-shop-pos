<?php

namespace App\Models;

use App\Enums\AddonBilling;
use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\ShopAddonStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopAddon extends Model
{
    protected $fillable = [
        'shop_id',
        'addon_id',
        'subscription_invoice_id',
        'status',
        'name',
        'kind',
        'limit_key',
        'quantity',
        'feature_key',
        'price',
        'billing',
        'starts_at',
        'ends_at',
        'purchased_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShopAddonStatus::class,
            'kind' => AddonKind::class,
            'limit_key' => LimitKey::class,
            'feature_key' => FeatureKey::class,
            'billing' => AddonBilling::class,
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    public function scopeInEffect(Builder $query): Builder
    {
        return $query->where('status', ShopAddonStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
