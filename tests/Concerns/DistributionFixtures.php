<?php

namespace Tests\Concerns;

use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use App\Support\MoneyFlow;
use Illuminate\Support\Facades\Hash;

/**
 * A wholesale trade buying from a vendor and selling on (Wholesale P2 tests):
 * the owner signed in (password secret), a bank account, one vendor and a
 * product (Tea, Rs 1,500, trade Rs 1,400, brand Tapal) with no stock yet.
 */
trait DistributionFixtures
{
    protected Shop $shop;

    protected User $owner;

    protected ShopAccount $bank;

    protected Vendor $vendor;

    protected Product $tea;

    protected function useShop(string $category, array $attributes = []): void
    {
        $this->shop = Shop::create(['name' => 'Al-Noor '.$category, 'business_category' => $category] + $attributes);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'password' => Hash::make('secret')]);
        $this->actingAs($this->owner);
        $this->bank = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);
        $this->vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Tapal Distributor']);
        $this->tea = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Tea 950g', 'price' => 1500, 'cost_price' => 1200, 'wholesale_price' => 1400, 'stock_quantity' => 0, 'details' => ['category' => 'Tea', 'brand' => 'Tapal']]);
    }

    protected function stock(int|float $quantity = 100, float $cost = 1200, string $status = 'unpaid', ?string $date = null, ?Product $product = null): StockIn
    {
        return CreateStockIn::handle([['product_id' => ($product ?? $this->tea)->id, 'quantity' => $quantity, 'cost_price' => $cost, 'selling_price' => 1500]], $this->vendor->id, $status, null, $date ?? now()->toDateString(), null, $this->owner->id)->first();
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + ['shop_id' => $this->shop->id, 'name' => 'Bilal', 'business_name' => 'Bilal Store']);
    }

    /** Tea at the normal price, straight through CreateSale. */
    protected function sell(Customer $customer, int $quantity, float $paid, ?string $date = null, array $extra = []): Sale
    {
        return CreateSale::handle([['product_id' => $this->tea->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null] + $extra], 0, $customer->id, $this->owner->id, $date ?? now()->toDateString(), $paid, paymentMethod: 'cash');
    }

    /** All money in less money out on Cash & Balances, every account and till, all time. */
    protected function netMoneyMoved(): float
    {
        return round((float) (new MoneyFlow($this->shop->id))->movements()->sum('amount'), 2);
    }
}
