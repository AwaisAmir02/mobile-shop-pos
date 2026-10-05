<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorStockInIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithProduct(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'cost_price' => 60, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);

        return [$shop, $owner, $product];
    }

    public function test_stock_in_can_be_saved_with_a_vendor(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('vendorId', (string) $vendor->id)
            ->set('quantity', '5')
            ->set('paymentStatus', 'paid')
            ->set('stock_date', now()->toDateString())
            ->call('save');

        $this->assertDatabaseHas('stock_ins', ['product_id' => $product->id, 'vendor_id' => $vendor->id]);
    }

    public function test_stock_in_can_be_saved_without_a_vendor(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '5')
            ->set('paymentStatus', 'paid')
            ->set('stock_date', now()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_ins', ['product_id' => $product->id, 'vendor_id' => null]);
    }

    public function test_historical_stock_entries_with_no_vendor_remain_valid_and_display_a_dash(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 2, 'stock_date' => now()->toDateString(), 'payment_status' => 'paid', 'amount_paid' => 0]);

        $this->get(route('stock-ins.history'))->assertSee('—');
    }

    public function test_a_tampered_vendor_id_from_another_shop_is_rejected(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        $shopB = Shop::create(['name' => 'Shop B']);
        $theirVendor = Vendor::create(['shop_id' => $shopB->id, 'name' => 'Their Vendor']);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('vendorId', (string) $theirVendor->id)
            ->set('quantity', '5')
            ->set('paymentStatus', 'paid')
            ->set('stock_date', now()->toDateString())
            ->call('save')
            ->assertHasErrors(['vendorId']);

        $this->assertSame(0, StockIn::count());
    }

    public function test_choosing_new_vendor_from_the_dropdown_opens_the_quick_create_modal(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();

        Livewire::test('stock-ins.create')
            ->set('vendorId', '__create__')
            ->assertSet('vendorId', '')
            ->assertDispatched('open-modal', name: 'quick-create-vendor');
    }

    public function test_the_quick_create_vendor_component_creates_the_vendor_and_dispatches_the_created_event(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();

        Livewire::test('vendors.quick-create')
            ->set('name', 'Fresh Vendor')
            ->set('phone', '03001234567')
            ->call('save')
            ->assertDispatched('vendor-created')
            ->assertDispatched('close-modal', name: 'quick-create-vendor');

        $this->assertDatabaseHas('vendors', ['shop_id' => $shop->id, 'name' => 'Fresh Vendor']);
    }

    public function test_stock_in_auto_selects_a_vendor_created_through_the_created_event(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Fresh Vendor']);

        Livewire::test('stock-ins.create')
            ->call('onVendorCreated', vendorId: $vendor->id, vendorName: $vendor->name)
            ->assertSet('vendorId', (string) $vendor->id);
    }

    public function test_quick_create_vendor_name_is_required_and_unique_per_shop(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);

        Livewire::test('vendors.quick-create')
            ->set('name', 'Acme Distributors')
            ->call('save')
            ->assertHasErrors(['name']);
    }
}
