<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_vendor_detail_page_shows_exact_summary_figures(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);

        StockIn::create(['shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Cable', 'quantity' => 5, 'total_cost' => 500, 'payment_status' => 'paid', 'amount_paid' => 500, 'stock_date' => now()->toDateString()]);
        StockIn::create(['shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Charger', 'quantity' => 3, 'total_cost' => 900, 'payment_status' => 'partial', 'amount_paid' => 300, 'stock_date' => now()->toDateString()]);

        Livewire::test('vendors.show', ['vendor' => $vendor])
            ->assertSee('2') // stock entries count appears in a stat card
            ->assertSee('8') // total units purchased: 5 + 3
            ->assertSee('1,400.00') // total purchase cost: 500 + 900
            ->assertSee('600.00'); // total owed: (900 - 300) partial only, paid entry owes 0
    }

    public function test_the_vendor_detail_page_lists_every_stock_entry_from_this_vendor(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);
        StockIn::create(['shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'product_name' => 'Cable', 'quantity' => 5, 'total_cost' => 500, 'payment_status' => 'paid', 'amount_paid' => 500, 'stock_date' => now()->toDateString(), 'note' => 'Bulk order']);

        Livewire::test('vendors.show', ['vendor' => $vendor])
            ->assertSee('Cable')
            ->assertSee('Bulk order');
    }

    public function test_vendor_detail_figures_are_isolated_from_another_vendors_entries(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $vendorA = Vendor::create(['shop_id' => $shop->id, 'name' => 'Vendor A']);
        $vendorB = Vendor::create(['shop_id' => $shop->id, 'name' => 'Vendor B']);

        StockIn::create(['shop_id' => $shop->id, 'vendor_id' => $vendorA->id, 'product_name' => 'A', 'quantity' => 5, 'total_cost' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => now()->toDateString()]);
        StockIn::create(['shop_id' => $shop->id, 'vendor_id' => $vendorB->id, 'product_name' => 'B', 'quantity' => 999, 'total_cost' => 99900, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => now()->toDateString()]);

        $component = Livewire::test('vendors.show', ['vendor' => $vendorA]);

        $component->assertSee('5');
        $component->assertDontSee('999');
    }

    public function test_a_vendor_from_another_shop_cannot_be_viewed(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $vendorB = Vendor::create(['shop_id' => $shopB->id, 'name' => 'Vendor B']);

        $this->actingAs($ownerA);

        $this->get(route('vendors.show', $vendorB))->assertStatus(404);
    }
}
