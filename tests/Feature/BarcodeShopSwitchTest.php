<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BarcodeShopSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_barcodes_are_enabled_by_default_for_a_new_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        $this->assertTrue($shop->fresh()->barcode_enabled);
    }

    public function test_super_admin_can_turn_barcodes_off_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleBarcodeEnabled');

        $this->assertFalse($shopA->fresh()->barcode_enabled);
        $this->assertTrue($shopB->fresh()->barcode_enabled);
    }

    public function test_turning_barcodes_back_on_restores_the_features(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => false]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleBarcodeEnabled');

        $this->assertTrue($shop->fresh()->barcode_enabled);
    }

    public function test_when_barcodes_are_off_the_field_and_actions_disappear_but_stored_barcodes_survive(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Zeta Widget', 'price' => 1, 'barcode' => '2000000000015', 'details' => []]);
        $this->actingAs($owner);

        $html = $this->get(route('products.index'))->getContent();

        $this->assertStringNotContainsString('wire:model="barcode"', $html);
        $this->assertStringNotContainsString('wire:click="generateBarcode"', $html);
        $this->assertStringNotContainsString('wire:click="runBulkBarcodeGeneration"', $html);
        $this->assertStringNotContainsString('openPrintLabel', $html);
        $this->assertStringNotContainsString('>Barcode<', $html);

        $this->assertSame('2000000000015', $product->fresh()->barcode);
    }

    public function test_saving_a_product_while_barcodes_are_off_does_not_overwrite_its_stored_barcode(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Zeta Widget', 'price' => 1, 'barcode' => '2000000000015', 'details' => ['category' => 'Cable']]);
        $this->actingAs($owner);

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->set('price', '99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2000000000015', $product->fresh()->barcode);
    }

    public function test_scanning_at_the_sale_screen_does_nothing_while_barcodes_are_off(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 5, 'stock_quantity' => 3, 'barcode' => '2000000000015', 'details' => []]);
        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->assertSet('cart', []);
    }

    public function test_the_barcode_switch_is_not_a_role_permission_so_an_existing_cashier_can_still_scan(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $cashier = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 5, 'stock_quantity' => 3, 'barcode' => '2000000000015', 'details' => []]);
        $this->actingAs($cashier);

        Livewire::test('sales.create')
            ->call('scanBarcode', '2000000000015')
            ->assertSet('cart.'.Product::first()->id.'.quantity', 1);
    }

    public function test_the_barcode_field_appears_in_the_stock_in_quick_create_modal_when_on(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $this->get(route('stock-ins.index'))->assertSee('quickProductBarcode', false);
    }

    public function test_the_barcode_field_is_absent_from_the_stock_in_quick_create_modal_when_off(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $this->get(route('stock-ins.index'))->assertDontSee('quickProductBarcode', false);
    }

    public function test_quick_create_saves_a_product_with_a_generated_barcode(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        \App\Models\AccessoryCategoryOption::ensureDefaultsExist();

        $component = Livewire::test('products.quick-create')
            ->set('type', 'accessory')
            ->set('name', 'Quick Cable')
            ->set('category', 'Cable')
            ->set('price', '100')
            ->call('generateBarcode');

        $generated = $component->get('barcode');
        $this->assertTrue(BarcodeService::isEan13($generated));

        $component->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'Quick Cable', 'barcode' => $generated]);
    }

    public function test_quick_create_names_the_product_that_already_owns_the_barcode(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Galaxy A15', 'price' => 1, 'barcode' => '2000000000015', 'details' => []]);

        Livewire::test('products.quick-create')
            ->set('type', 'accessory')
            ->set('name', 'Quick Cable')
            ->set('category', 'Cable')
            ->set('price', '100')
            ->set('barcode', '2000000000015')
            ->call('save')
            ->assertHasErrors(['barcode'])
            ->assertSee("Barcode already used by 'Galaxy A15'.");
    }

    public function test_the_quick_create_generate_button_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $html = $this->get(route('stock-ins.index'))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);
        $snapshotOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);
            if (($snapshot['memo']['name'] ?? null) === 'products.quick-create') {
                $snapshotOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($snapshotOffset, 'Could not find a wire:snapshot for products.quick-create.');
        $this->assertGreaterThan($snapshotOffset, strpos($html, 'wire:click="generateBarcode"'));
    }
}
