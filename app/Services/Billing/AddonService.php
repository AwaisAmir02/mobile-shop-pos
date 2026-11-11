<?php

namespace App\Services\Billing;

use App\Enums\AddonBilling;
use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\ShopAddonStatus;
use App\Models\Addon;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionBillingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddonService
{
    public function __construct(
        protected SubscriptionBillingService $billing,
        protected PaymentGateways $gateways,
        protected Entitlements $entitlements,
        protected BillingCycle $cycle,
    ) {}

    public function purchase(Shop $shop, Addon $addon, ?int $userId): ShopAddon
    {
        if (! $addon->is_active) {
            throw new RuntimeException('This add-on is no longer available.');
        }

        if (! $this->entitlements->governed($shop)) {
            throw new RuntimeException('Add-ons are available once your shop is on a plan.');
        }

        if ($this->cycle->enabled($shop)) {
            return $this->purchaseWithPlan($shop, $addon, $userId);
        }

        if (SubscriptionPayment::openFor($shop->id)) {
            throw new RuntimeException('Please finish your pending payment first, then buy the add-on.');
        }

        if ($addon->kind === AddonKind::FeatureUnlock && $this->entitlements->planIncludesAll($shop, $addon->feature_keys)) {
            throw new RuntimeException('Your plan already includes '.$addon->featuresLabel().'.');
        }

        return DB::transaction(function () use ($shop, $addon, $userId) {
            $invoice = $this->billing->create($shop, [
                'period_start' => now()->toDateString(),
                'months' => $addon->billing->months(),
                'amount' => (float) $addon->price,
                'notes' => 'Add-on: '.$addon->name,
            ], $userId);

            $shopAddon = ShopAddon::create([
                'shop_id' => $shop->id,
                'addon_id' => $addon->id,
                'subscription_invoice_id' => $invoice->id,
                'status' => ShopAddonStatus::Pending,
                'name' => $addon->name,
                'kind' => $addon->kind,
                'limit_key' => $addon->limit_key,
                'quantity' => $addon->quantity,
                'feature_keys' => $addon->feature_keys,
                'price' => $addon->price,
                'billing' => $addon->billing,
                'purchased_by' => $userId,
            ]);

            $this->gateways->current()->start($invoice);

            return $shopAddon;
        });
    }

    /**
     * On automatic billing an add-on starts straight away and is paid with
     * the plan: the rest of this cycle pro-rated, then each renewal in full
     * (see BillingCycle::chargeNewAddon). No invoice or payment of its own.
     */
    protected function purchaseWithPlan(Shop $shop, Addon $addon, ?int $userId): ShopAddon
    {
        if ($this->cycle->locked($shop)) {
            throw new RuntimeException('Please complete your plan payment first, then add the add-on.');
        }

        if (SubscriptionPayment::openFor($shop->id)?->isAwaitingReview()) {
            throw new RuntimeException('We’re checking your last payment (this can take up to a day). You can add add-ons once it’s confirmed.');
        }

        if ($addon->kind === AddonKind::FeatureUnlock && $this->entitlements->planIncludesAll($shop, $addon->feature_keys)) {
            throw new RuntimeException('Your plan already includes '.$addon->featuresLabel().'.');
        }

        if ($addon->kind === AddonKind::FeatureUnlock && ShopAddon::where('shop_id', $shop->id)->where('addon_id', $addon->id)->where('plan_billed', true)->inEffect()->exists()) {
            throw new RuntimeException('You already have '.$addon->name.'.');
        }

        return DB::transaction(function () use ($shop, $addon, $userId) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();

            $shopAddon = ShopAddon::create([
                'shop_id' => $shop->id,
                'addon_id' => $addon->id,
                'plan_billed' => true,
                'status' => ShopAddonStatus::Active,
                'name' => $addon->name,
                'kind' => $addon->kind,
                'limit_key' => $addon->limit_key,
                'quantity' => $addon->quantity,
                'feature_keys' => $addon->feature_keys,
                'price' => $addon->price,
                'billing' => $addon->billing,
                'starts_at' => now(),
                // Recurring ones run with the plan; a one-off ends with the cycle it was bought in.
                'ends_at' => $addon->billing === AddonBilling::OneOff ? $this->cycle->oneOffEndsAt($shop) : null,
                'purchased_by' => $userId,
            ]);

            $this->cycle->chargeNewAddon($shopAddon, $shop);

            $this->entitlements->syncFeatureFlags($shop->fresh());
            $this->entitlements->forgetMeters($shop);

            return $shopAddon->fresh();
        });
    }

    public function activate(ShopAddon $shopAddon): ShopAddon
    {
        $shopAddon = ShopAddon::whereKey($shopAddon->id)->lockForUpdate()->firstOrFail();

        if ($shopAddon->status !== ShopAddonStatus::Pending) {
            return $shopAddon;
        }

        $shop = Shop::findOrFail($shopAddon->shop_id);
        $startsAt = $this->startsAt($shop, $shopAddon);

        $shopAddon->update([
            'status' => ShopAddonStatus::Active,
            'starts_at' => $startsAt,
            'ends_at' => $this->endsAt($shop, $shopAddon, $startsAt),
        ]);

        $this->entitlements->syncFeatureFlags($shop->fresh());
        $this->entitlements->forgetMeters($shop);

        return $shopAddon->fresh();
    }

    /**
     * A recurring limit pack (Branches) bought again while the shop still
     * holds it is a renewal: it starts when the current one ends, so the
     * extra branch is never counted twice and never lapses in between.
     */
    protected function startsAt(Shop $shop, ShopAddon $shopAddon): CarbonInterface
    {
        if ($shopAddon->kind !== AddonKind::LimitPack || $shopAddon->billing === AddonBilling::OneOff || $shopAddon->addon_id === null) {
            return now();
        }

        $current = ShopAddon::where('shop_id', $shop->id)
            ->where('addon_id', $shopAddon->addon_id)
            ->whereKeyNot($shopAddon->id)
            ->where('status', ShopAddonStatus::Active->value)
            ->where('ends_at', '>', now())
            ->max('ends_at');

        return $current ? now()->parse($current) : now();
    }

    protected function endsAt(Shop $shop, ShopAddon $shopAddon, CarbonInterface $startsAt): CarbonInterface
    {
        if ($shopAddon->kind === AddonKind::LimitPack && $shopAddon->billing !== AddonBilling::OneOff) {
            return $startsAt->copy()->addMonthsNoOverflow($shopAddon->billing->months());
        }

        if ($shopAddon->kind === AddonKind::LimitPack) {
            $renewal = $shop->expectedRenewalDate();

            return $renewal && $renewal->isFuture() ? $renewal->copy()->endOfDay() : now()->addMonthNoOverflow();
        }

        // Stacks after an unlock in effect that already covers every feature this one does.
        $current = ShopAddon::where('shop_id', $shop->id)
            ->where(fn ($query) => collect($shopAddon->feature_keys)->each(fn (FeatureKey $feature) => $query->whereJsonContains('feature_keys', $feature->value)))
            ->whereKeyNot($shopAddon->id)
            ->inEffect()
            ->max('ends_at');

        $from = $current ? now()->parse($current) : now();

        return $from->copy()->addMonthsNoOverflow($shopAddon->billing->months());
    }

    public function expireDue(): int
    {
        $due = ShopAddon::where('status', ShopAddonStatus::Active->value)->whereNotNull('ends_at')->where('ends_at', '<=', now())->get();

        foreach ($due as $shopAddon) {
            $shopAddon->update(['status' => ShopAddonStatus::Expired]);
        }

        Shop::whereIn('id', $due->pluck('shop_id')->unique())->get()->each(function (Shop $shop) {
            $this->entitlements->syncFeatureFlags($shop);
            $this->entitlements->forgetMeters($shop);
        });

        return $due->count();
    }
}
