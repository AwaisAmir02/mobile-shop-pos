<?php

namespace App\Services;

use App\Enums\BusinessCategory;
use App\Enums\ShopScreen;
use App\Models\MainCategory;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;

class BusinessCategories
{
    public static function modules(): array
    {
        return [
            'balance-loads' => ['label' => 'Balance loads', 'description' => 'Mobile load sales and their fees.', 'screens' => [ShopScreen::BalanceLoads, ShopScreen::BalanceLoadsEditCancel]],
            'wallet-loads' => ['label' => 'Wallet loads', 'description' => 'Wallet cash-in and cash-out with fees.', 'screens' => [ShopScreen::WalletLoads, ShopScreen::WalletLoadsEditCancel]],
            'sim-sales' => ['label' => 'SIM sales', 'description' => 'SIM sales by network, plus the Networks settings.', 'screens' => [ShopScreen::SimSales, ShopScreen::SimSalesEditCancel, ShopScreen::SettingsNetworks]],
            'bills' => ['label' => 'Bills', 'description' => 'Utility bill collection, plus the Bill providers settings.', 'screens' => [ShopScreen::Bills, ShopScreen::BillsEditCancel, ShopScreen::SettingsBillConfig]],
            'nadra' => ['label' => 'NADRA verification', 'description' => 'NADRA verifications and their fees.', 'screens' => [ShopScreen::NadraVerifications, ShopScreen::NadraVerificationsEditCancel]],
            'repairs' => ['label' => 'Repairs', 'description' => 'Device repair job tracking.', 'screens' => [ShopScreen::Repairs, ShopScreen::RepairsEditCancel]],
        ];
    }

    public static function hiddenModules(Shop $shop): array
    {
        $hidden = $shop->hidden_screens ?? [];

        return array_keys(array_filter(self::modules(), fn (array $module) => in_array($module['screens'][0]->value, $hidden, true)));
    }

    public function setHiddenModules(Shop $shop, array $moduleKeys): void
    {
        $modules = self::modules();
        $moduleKeys = array_values(array_intersect($moduleKeys, array_keys($modules)));

        $screens = collect($moduleKeys)->flatMap(fn (string $key) => array_map(fn (ShopScreen $screen) => $screen->value, $modules[$key]['screens']));

        if (empty(array_diff(['sim-sales', 'balance-loads', 'wallet-loads'], $moduleKeys))) {
            $screens->push(ShopScreen::SettingsPercentage->value);
        }

        $shop->update(['hidden_screens' => $screens->isEmpty() ? null : $screens->unique()->values()->all()]);
    }

    public function applyDefaults(Shop $shop): void
    {
        $category = $shop->business_category;

        if ($category === null) {
            return;
        }

        $this->setHiddenModules($shop, $category->showsMobileModules() ? [] : array_keys(self::modules()));
        $this->createStarterCategories($shop, $category);
    }

    protected function createStarterCategories(Shop $shop, BusinessCategory $category): void
    {
        if ($category->starterCategories() === []) {
            return;
        }

        MainCategory::ensureDefaultsExist($shop->id);

        foreach ($category->starterCategories() as $name) {
            $query = MainCategory::withoutGlobalScope(ShopScope::class)->where('shop_id', $shop->id);

            if ((clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                continue;
            }

            $base = MainCategory::slugFor($name);
            $slug = $base;
            $attempt = 1;

            while ((clone $query)->where('slug', $slug)->exists()) {
                $slug = $base.'-'.(++$attempt);
            }

            MainCategory::create(['shop_id' => $shop->id, 'name' => $name, 'slug' => $slug, 'is_builtin' => false]);
        }
    }
}
