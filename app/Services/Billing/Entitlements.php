<?php

namespace App\Services\Billing;

use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\NotificationStatus;
use App\Enums\PostKind;
use App\Enums\SaleStatus;
use App\Enums\StoreOrderStatus;
use App\Exceptions\UsageLimitReached;
use App\Models\Branch;
use App\Models\CustomerNotification;
use App\Models\MarketingPost;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\WhatsAppOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class Entitlements
{
    public const TIMEZONE = 'Asia/Karachi';

    public const WARNING_PERCENT = 80;

    public function governed(Shop $shop): bool
    {
        return $shop->plan_id !== null;
    }

    public function limit(Shop $shop, LimitKey $key): ?int
    {
        if (! $this->governed($shop)) {
            return null;
        }

        return $this->limitOn($shop, $this->plan($shop), $key);
    }

    /**
     * What $shop's limit would be on $plan: the shop's own override first,
     * else the plan's limit, plus any add-on packs in effect. Used for the
     * current plan and to check a plan the owner wants to switch to.
     */
    public function limitOn(Shop $shop, ?Plan $plan, LimitKey $key): ?int
    {
        $overrides = $shop->limit_overrides ?? [];
        $planLimits = $plan?->limits ?? [];

        $base = array_key_exists($key->value, $overrides) ? $overrides[$key->value] : ($planLimits[$key->value] ?? null);

        if ($base === null) {
            return null;
        }

        return (int) $base + $this->addonQuantity($shop, $key);
    }

    protected function addonQuantity(Shop $shop, LimitKey $key): int
    {
        return (int) ShopAddon::where('shop_id', $shop->id)
            ->where('kind', AddonKind::LimitPack->value)
            ->where('limit_key', $key->value)
            ->inEffect()
            ->sum('quantity');
    }

    public function unlockedByAddon(Shop $shop, FeatureKey $feature): bool
    {
        return ShopAddon::where('shop_id', $shop->id)
            ->where('kind', AddonKind::FeatureUnlock->value)
            ->whereJsonContains('feature_keys', $feature->value)
            ->inEffect()
            ->exists();
    }

    public function monthRange(): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        return [$now->startOfMonth()->utc(), $now->endOfMonth()->utc()];
    }

    public function usage(Shop $shop, LimitKey $key): int
    {
        [$start, $end] = $this->monthRange();

        return match ($key) {
            // A variant group counts as one product, however many variants it has.
            LimitKey::Products => (int) Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('archived_at')
                ->selectRaw('COUNT(*) - COUNT(product_group_id) + COUNT(DISTINCT product_group_id) as aggregate')
                ->value('aggregate'),
            LimitKey::StaffUsers => User::where('shop_id', $shop->id)->where('is_owner', false)->where('is_active', true)->count(),
            LimitKey::SalesPerMonth => Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->where('status', '!=', SaleStatus::Cancelled->value)->whereBetween('created_at', [$start, $end])->count(),
            LimitKey::WhatsAppMessagesPerMonth => CustomerNotification::withoutGlobalScopes()->where('shop_id', $shop->id)->where('status', NotificationStatus::Sent->value)->whereBetween('created_at', [$start, $end])->count(),
            LimitKey::Branches => Branch::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('archived_at')->count(),
            // Orders the customer placed (Confirm tapped) this month, whatever the shop did with them.
            LimitKey::WhatsAppOrdersPerMonth => WhatsAppOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->whereBetween('submitted_at', [$start, $end])->count(),
            // Accepted, not received: an order the customer already bought is always stored.
            LimitKey::OnlineOrdersPerMonth => StoreOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->where('status', StoreOrderStatus::Accepted->value)->whereBetween('decided_at', [$start, $end])->count(),
            LimitKey::MarketingPostsPerMonth => MarketingPost::withoutGlobalScopes()->where('shop_id', $shop->id)->where('kind', '!=', PostKind::QrCode->value)->whereBetween('created_at', [$start, $end])->count(),
        };
    }

    public function remaining(Shop $shop, LimitKey $key): ?int
    {
        $limit = $this->limit($shop, $key);

        return $limit === null ? null : max(0, $limit - $this->usage($shop, $key));
    }

    public function allows(Shop $shop, LimitKey $key, int $adding = 1): bool
    {
        $limit = $this->limit($shop, $key);

        return $limit === null || $this->usage($shop, $key) + $adding <= $limit;
    }

    public function ensureCanAdd(Shop $shop, LimitKey $key, int $adding = 1): void
    {
        if ($key->blocksAtLimit() && ! $this->allows($shop, $key, $adding)) {
            throw new UsageLimitReached($key, (int) $this->limit($shop, $key));
        }
    }

    /**
     * $everyLimit keeps the Branches meter for a single-branch shop; only
     * the owner's Plan & Usage screen asks for that, always fresh.
     */
    public function meters(Shop $shop, bool $fresh = false, bool $everyLimit = false): array
    {
        if (! $this->governed($shop)) {
            return [];
        }

        $build = fn () => collect(LimitKey::cases())
            ->map(fn (LimitKey $key) => $this->meter($shop, $key))
            // A single-branch shop must look exactly as it did before
            // branches existed: no meter, no banner, no admin alert.
            ->reject(fn (array $meter) => $meter['key'] === LimitKey::Branches->value && $meter['used'] <= 1 && ! $everyLimit)
            ->values()
            ->all();

        return $fresh || $everyLimit ? $build() : Cache::remember('entitlement-meters:'.$shop->id, 60, $build);
    }

    /** One limit's meter, always fresh (meters() lists them all, cached). */
    public function meter(Shop $shop, LimitKey $key): array
    {
        $limit = $this->limit($shop, $key);
        $used = $this->usage($shop, $key);
        $percent = $limit === null ? 0 : ($limit === 0 ? ($used > 0 ? 100 : 0) : (int) min(100, round($used / $limit * 100)));

        return [
            'key' => $key->value,
            'label' => $key->label(),
            'used' => $used,
            'limit' => $limit,
            'percent' => $percent,
            'state' => $limit === null ? 'ok' : ($used >= $limit ? 'full' : ($percent >= self::WARNING_PERCENT ? 'warning' : 'ok')),
            'over' => $limit !== null && $used > $limit,
            'alarm' => $key->alarmsAtLimit(),
        ];
    }

    /**
     * Each feature as the shop has it now, and why: 'hisabokitab' (an admin
     * override, or a shop with no plan), 'addon' (an unlock in effect, with
     * its latest end date; null means it doesn't end) or 'plan'. Follows the
     * same order as syncFeatureFlags().
     *
     * @return array<int, array{key: FeatureKey, label: string, on: bool, source: string, until: ?\Carbon\CarbonInterface}>
     */
    public function features(Shop $shop): array
    {
        return collect(FeatureKey::cases())->map(function (FeatureKey $feature) use ($shop) {
            $row = ['key' => $feature, 'label' => $feature->label(), 'on' => (bool) $shop->{$feature->column()}, 'source' => 'plan', 'until' => null];

            if (! $this->governed($shop) || ! $feature->planControlled() || ($override = $this->featureOverride($shop, $feature)) !== null) {
                return array_merge($row, ['on' => $override ?? $row['on'], 'source' => 'hisabokitab']);
            }

            $unlocks = ShopAddon::where('shop_id', $shop->id)
                ->where('kind', AddonKind::FeatureUnlock->value)
                ->whereJsonContains('feature_keys', $feature->value)
                ->inEffect()
                ->get('ends_at');

            if ($unlocks->isNotEmpty()) {
                return array_merge($row, ['on' => true, 'source' => 'addon', 'until' => $unlocks->contains(fn (ShopAddon $a) => $a->ends_at === null) ? null : $unlocks->max('ends_at')]);
            }

            $planned = $this->planFeature($shop, $feature);

            // A plan that says nothing about a feature leaves the shop's own
            // switch alone, so an "on" there was set by HisabOKitab.
            return $planned === null && $row['on'] ? array_merge($row, ['source' => 'hisabokitab']) : array_merge($row, ['on' => $planned ?? false]);
        })->all();
    }

    public function forgetMeters(Shop $shop): void
    {
        Cache::forget('entitlement-meters:'.$shop->id);
    }

    public function planFeature(Shop $shop, FeatureKey $feature): ?bool
    {
        $flags = $this->plan($shop)?->feature_flags ?? [];

        return array_key_exists($feature->value, $flags) ? (bool) $flags[$feature->value] : null;
    }

    /** Whether the plan switches on every one of $features (an add-on would add nothing). */
    public function planIncludesAll(Shop $shop, iterable $features): bool
    {
        $features = collect($features);

        return $features->isNotEmpty() && $features->every(fn (FeatureKey $feature) => $this->planFeature($shop, $feature) === true);
    }

    public function featureOverride(Shop $shop, FeatureKey $feature): ?bool
    {
        $overrides = $shop->feature_overrides ?? [];

        return array_key_exists($feature->value, $overrides) ? (bool) $overrides[$feature->value] : null;
    }

    public function syncFeatureFlags(Shop $shop): void
    {
        if (! $this->governed($shop)) {
            return;
        }

        foreach (FeatureKey::planCases() as $feature) {
            $value = $this->featureOverride($shop, $feature) ?? ($this->unlockedByAddon($shop, $feature) ? true : $this->planFeature($shop, $feature));

            if ($value !== null) {
                $shop->{$feature->column()} = $value;
            }
        }

        if ($shop->isDirty()) {
            $shop->save();
        }
    }

    public function setFeatureOverride(Shop $shop, FeatureKey $feature, ?bool $value): void
    {
        $overrides = $shop->feature_overrides ?? [];

        if ($value === null) {
            unset($overrides[$feature->value]);
        } else {
            $overrides[$feature->value] = $value;
        }

        $shop->feature_overrides = $overrides === [] ? null : $overrides;

        if ($value !== null) {
            $shop->{$feature->column()} = $value;
        }

        $shop->save();
        $this->syncFeatureFlags($shop);
    }

    public function syncPlan(Plan $plan): void
    {
        Shop::where('plan_id', $plan->id)->each(function (Shop $shop) {
            $this->syncFeatureFlags($shop);
            $this->forgetMeters($shop);
        });
    }

    protected function plan(Shop $shop): ?Plan
    {
        return $shop->plan_id ? $shop->plan()->first() : null;
    }
}
