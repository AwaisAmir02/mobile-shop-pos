<?php

namespace App\Services\Billing;

use App\Enums\AddonKind;
use App\Enums\LimitKey;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Shop;

/**
 * The limit notice a screen shows for the limit it is about (Products on
 * the products list, Staff users on Team, …). Scoped to the request: each
 * notice shown is remembered so the global usage banner, which renders
 * after the page content, leaves that limit out instead of repeating it.
 */
class LimitNotices
{
    /** @var array<string, true> */
    protected array $shown = [];

    public function __construct(protected Entitlements $entitlements) {}

    /**
     * Null when there is nothing to say: no plan, unlimited, or comfortably
     * under the limit. Thresholds and severity match the usage banner.
     *
     * @return array{meter: array, state: string, alarming: bool, pack: bool, upgrade: bool}|null
     */
    public function for(Shop $shop, LimitKey $key, bool $informational = false): ?array
    {
        if (! $this->entitlements->governed($shop)) {
            return null;
        }

        $meter = $this->entitlements->meter($shop, $key);

        if ($meter['limit'] === null || $meter['state'] === 'ok') {
            return null;
        }

        $this->shown[$key->value] = true;

        return [
            'meter' => $meter,
            'state' => $meter['over'] ? 'over' : $meter['state'],
            'alarming' => $meter['state'] === 'full' && $meter['alarm'],
            'pack' => ! $informational && Addon::active()->where('kind', AddonKind::LimitPack->value)->where('limit_key', $key->value)->exists(),
            'upgrade' => ! $informational && $this->higherPlanExists($shop, $key, $meter['limit']),
        ];
    }

    /** @return array<int, string> limit keys a notice on this page already covers */
    public function shown(): array
    {
        return array_keys($this->shown);
    }

    protected function higherPlanExists(Shop $shop, LimitKey $key, int $current): bool
    {
        return Plan::active()->whereKeyNot($shop->plan_id)->get()
            ->contains(fn (Plan $plan) => ($limit = $this->entitlements->limitOn($shop, $plan, $key)) === null || $limit > $current);
    }
}
