<?php

namespace App\Models;

use App\Enums\AddonBilling;
use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Addon extends Model
{
    protected $fillable = [
        'name',
        'description',
        'kind',
        'limit_key',
        'quantity',
        'feature_keys',
        'price',
        'billing',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AddonKind::class,
            'limit_key' => LimitKey::class,
            'feature_keys' => AsEnumCollection::of(FeatureKey::class),
            'billing' => AddonBilling::class,
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ShopAddon::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function grants(): string
    {
        return $this->kind === AddonKind::LimitPack
            ? '+'.number_format((int) $this->quantity).' '.$this->limit_key?->unit((int) $this->quantity).($this->limit_key?->isMonthly() ? ' a month' : '')
            : $this->featuresLabel().' switched on';
    }

    /** "WhatsApp ordering & WhatsApp messaging" for a bundle. */
    public function featuresLabel(): string
    {
        return collect($this->feature_keys)->map(fn (FeatureKey $feature) => $feature->label())->implode(' & ');
    }

    public function priceLabel(): string
    {
        return 'Rs '.number_format((float) $this->price, fmod((float) $this->price, 1.0) === 0.0 ? 0 : 2).' '.$this->billing->suffix();
    }
}
