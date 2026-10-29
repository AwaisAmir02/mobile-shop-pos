<?php

namespace App\Services\Billing;

use App\Enums\AddonKind;
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
    ) {}

    public function purchase(Shop $shop, Addon $addon, ?int $userId): ShopAddon
    {
        if (! $addon->is_active) {
            throw new RuntimeException('This add-on is no longer available.');
        }

        if (! $this->entitlements->governed($shop)) {
            throw new RuntimeException('Add-ons are available once your shop is on a plan.');
        }

        if (SubscriptionPayment::openFor($shop->id)) {
            throw new RuntimeException('Please finish your pending payment first, then buy the add-on.');
        }

        if ($addon->kind === AddonKind::FeatureUnlock && $this->entitlements->planFeature($shop, $addon->feature_key) === true) {
            throw new RuntimeException('Your plan already includes '.$addon->feature_key->label().'.');
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
                'feature_key' => $addon->feature_key,
                'price' => $addon->price,
                'billing' => $addon->billing,
                'purchased_by' => $userId,
            ]);

            $this->gateways->current()->start($invoice);

            return $shopAddon;
        });
    }

    public function activate(ShopAddon $shopAddon): ShopAddon
    {
        $shopAddon = ShopAddon::whereKey($shopAddon->id)->lockForUpdate()->firstOrFail();

        if ($shopAddon->status !== ShopAddonStatus::Pending) {
            return $shopAddon;
        }

        $shop = Shop::findOrFail($shopAddon->shop_id);

        $shopAddon->update([
            'status' => ShopAddonStatus::Active,
            'starts_at' => now(),
            'ends_at' => $this->endsAt($shop, $shopAddon),
        ]);

        $this->entitlements->syncFeatureFlags($shop->fresh());
        $this->entitlements->forgetMeters($shop);

        return $shopAddon->fresh();
    }

    protected function endsAt(Shop $shop, ShopAddon $shopAddon): CarbonInterface
    {
        if ($shopAddon->kind === AddonKind::LimitPack) {
            $renewal = $shop->expectedRenewalDate();

            return $renewal && $renewal->isFuture() ? $renewal->copy()->endOfDay() : now()->addMonthNoOverflow();
        }

        $current = ShopAddon::where('shop_id', $shop->id)
            ->where('feature_key', $shopAddon->feature_key?->value)
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
