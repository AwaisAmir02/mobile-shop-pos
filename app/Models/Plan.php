<?php

namespace App\Models;

use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\PlanType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'tagline',
        'price',
        'billing_interval',
        'trial_days',
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
            'trial_days' => 'integer',
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

    /** The plan's limit for $key; null (or a key the plan never set) is unlimited. */
    public function limitFor(LimitKey $key): ?int
    {
        $value = ($this->limits ?? [])[$key->value] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Whether the plan really gives this feature: ticked, and not capped at
     * zero (a feature allowed 0 uses a month can't be used).
     */
    public function includes(FeatureKey $feature): bool
    {
        return (bool) (($this->feature_flags ?? [])[$feature->value] ?? false)
            && ($feature->limit() === null || $this->limitFor($feature->limit()) !== 0);
    }

    /** @return array<int, FeatureKey> */
    public function includedFeatures(): array
    {
        return array_values(array_filter(FeatureKey::pricingOrder(), fn (FeatureKey $feature) => $this->includes($feature)));
    }

    /**
     * Features at least one of $plans includes. The pricing page lists only
     * these as "not included" on the plans that lack them; a feature no plan
     * sells isn't mentioned at all.
     *
     * @param  iterable<int, self>  $plans
     * @return array<int, FeatureKey>
     */
    public static function offeredFeatures(iterable $plans): array
    {
        $offered = collect($plans)->flatMap(fn (self $plan) => $plan->includedFeatures());

        return array_values(array_filter(FeatureKey::pricingOrder(), fn (FeatureKey $feature) => $offered->contains($feature)));
    }

    /**
     * The plan's lines on the pricing page and at signup, built from its
     * limits and features so they can't drift from what the plan does:
     * core limits first, then the features it includes (with their limit
     * when they have one), then the offered ones it doesn't.
     *
     * @param  array<int, FeatureKey>|null  $offered  features to show as "not included" when missing; null shows every plan feature
     * @return array<int, array{label: string, detail: ?string, included: bool}>
     */
    public function pricingLines(?array $offered = null): array
    {
        $lines = array_map(fn (LimitKey $key) => ['label' => $key->amountLabel($this->limitFor($key)), 'detail' => null, 'included' => true], LimitKey::core());
        $missing = [];

        foreach (FeatureKey::pricingOrder() as $feature) {
            $included = $this->includes($feature);

            if (! $included && $offered !== null && ! in_array($feature, $offered, true)) {
                continue;
            }

            if (! $included) {
                $missing[] = ['label' => $feature->label(), 'detail' => null, 'included' => false];

                continue;
            }

            $limit = $feature->limit();
            $lines[] = ['label' => $feature->label(), 'detail' => $limit?->amountLabel($this->limitFor($limit)), 'included' => true];
        }

        return [...$lines, ...$missing];
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
