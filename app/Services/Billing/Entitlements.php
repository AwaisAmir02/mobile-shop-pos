<?php

namespace App\Services\Billing;

use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\NotificationStatus;
use App\Enums\PostKind;
use App\Enums\SaleStatus;
use App\Exceptions\UsageLimitReached;
use App\Models\CustomerNotification;
use App\Models\MarketingPost;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\User;
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

        $overrides = $shop->limit_overrides ?? [];
        $planLimits = $this->plan($shop)?->limits ?? [];

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
            ->where('feature_key', $feature->value)
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
            LimitKey::Products => Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('archived_at')->count(),
            LimitKey::StaffUsers => User::where('shop_id', $shop->id)->where('is_owner', false)->where('is_active', true)->count(),
            LimitKey::SalesPerMonth => Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->where('status', '!=', SaleStatus::Cancelled->value)->whereBetween('created_at', [$start, $end])->count(),
            LimitKey::WhatsAppMessagesPerMonth => CustomerNotification::withoutGlobalScopes()->where('shop_id', $shop->id)->where('status', NotificationStatus::Sent->value)->whereBetween('created_at', [$start, $end])->count(),
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

    public function meters(Shop $shop, bool $fresh = false): array
    {
        if (! $this->governed($shop)) {
            return [];
        }

        $build = fn () => collect(LimitKey::cases())->map(function (LimitKey $key) use ($shop) {
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
            ];
        })->all();

        return $fresh ? $build() : Cache::remember('entitlement-meters:'.$shop->id, 60, $build);
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

        foreach (FeatureKey::cases() as $feature) {
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
