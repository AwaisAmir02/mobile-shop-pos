<?php

namespace Tests\Concerns;

use App\Actions\CreateSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A wholesaler selling over the counter (Wholesale P1 tests): the owner signed
 * in (password owner-secret), a product with a trade price, business customers.
 */
trait WholesaleShopFixtures
{
    protected Shop $shop;

    protected User $owner;

    protected function openWholesaleShop(): void
    {
        // Midday UTC: the shop's day (Karachi) and the server's day agree.
        Carbon::setTestNow('2026-11-20 08:00:00');

        $this->shop = Shop::create(['name' => 'Al-Madina Traders', 'business_category' => 'wholesaler', 'phone' => '042 1112223']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'password' => Hash::make('owner-secret')]);
        $this->actingAs($this->owner);
    }

    /** Rs 100, trade price Rs 90, 500 in stock. */
    protected function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Ghee 1kg', 'price' => 100, 'cost_price' => 70,
            'wholesale_price' => 90, 'stock_quantity' => 500, 'details' => ['category' => 'Grocery'],
        ], $attributes));
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge(['shop_id' => $this->shop->id, 'name' => 'Bilal', 'business_name' => 'Bilal General Store', 'phone' => '03001234567'], $attributes));
    }

    /** Straight through CreateSale, so at the normal price (the register is what picks the trade price). */
    protected function sell(?Customer $customer, Product $product, int $quantity, float $paid, ?string $date = null, array $attributes = []): Sale
    {
        return CreateSale::handle(
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0, customerId: $customer?->id, userId: $this->owner->id,
            saleDate: $date ?? now()->toDateString(), amountPaid: $paid, paymentMethod: 'cash', attributes: $attributes,
        );
    }
}
