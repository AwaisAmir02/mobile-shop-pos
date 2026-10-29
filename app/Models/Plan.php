<?php

namespace App\Models;

use App\Enums\PlanType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    public const MAX_FEATURES = 20;

    protected $fillable = [
        'name',
        'tagline',
        'price',
        'billing_interval',
        'features',
        'limits',
        'feature_flags',
        'is_active',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_interval' => PlanType::class,
            'features' => 'array',
            'limits' => 'array',
            'feature_flags' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function featureList(): array
    {
        return collect($this->features ?? [])
            ->filter(fn ($feature) => is_array($feature) && trim((string) ($feature['label'] ?? '')) !== '')
            ->map(fn ($feature) => ['label' => trim((string) $feature['label']), 'included' => (bool) ($feature['included'] ?? false)])
            ->values()
            ->all();
    }

    public function intervalLabel(): string
    {
        return $this->billing_interval === PlanType::Yearly ? 'year' : 'month';
    }

    public function priceLabel(): string
    {
        return 'Rs '.number_format((float) $this->price, fmod((float) $this->price, 1.0) === 0.0 ? 0 : 2);
    }
}
