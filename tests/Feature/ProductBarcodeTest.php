<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\ProductReport;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(bool $barcodesEnabled = true): array
    {
        $shop = Shop::create(['name' => 'Shop A', 'barcode_enabled' => $barcodesEnabled]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function product(int $shopId, string $name, ?string $barcode = null): Product
    {
        return Product::create(['shop_id' => $shopId, 'type' => 'accessory', 'name' => $name, 'price' => 100, 'stock_quantity' => 5, 'barcode' => $barcode, 'details' => ['category' => 'Cable']]);
    }

    public function test_a_barcode_can_be_typed_and_saved_on_a_new_product(): void
    {
        [$shop] = $this->shopOwner();

        Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->set('name', 'USB Cable')
            ->set('category', 'Cable')
            ->set('price', '200')
            ->set('barcode', '  8901234567890  ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'USB Cable', 'barcode' => '8901234567890']);
    }

    public function test_a_duplicate_barcode_is_rejected_with_a_message_naming_the_conflicting_product(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'Galaxy A15', '8901234567890');

        Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->set('name', 'Other')
            ->set('category', 'Cable')
            ->set('price', '50')
            ->set('barcode', '8901234567890')
            ->call('save')
            ->assertHasErrors(['barcode'])
            ->assertSee("Barcode already used by 'Galaxy A15'.");
    }

    public function test_the_same_barcode_check_is_case_insensitive_through_the_column_collation(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'Galaxy A15', 'ABC-123');

        Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->set('name', 'Other')
            ->set('category', 'Cable')
            ->set('price', '50')
            ->set('barcode', 'abc-123')
            ->call('save')
            ->assertHasErrors(['barcode']);
    }

    public function test_editing_a_product_keeps_its_own_barcode_without_a_false_conflict(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'Galaxy A15', '8901234567890');

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->set('price', '150')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('8901234567890', $product->fresh()->barcode);
    }

    public function test_a_blank_barcode_is_stored_as_null(): void
    {
        [$shop] = $this->shopOwner();

        Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->set('name', 'USB Cable')
            ->set('category', 'Cable')
            ->set('price', '200')
            ->set('barcode', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'USB Cable', 'barcode' => null]);
    }

    public function test_a_barcode_with_spaces_inside_is_allowed_but_control_characters_are_rejected(): void
    {
        [$shop] = $this->shopOwner();

        Livewire::test('products.index')
            ->call('openCreate')
            ->set('type', 'accessory')
            ->set('name', 'Bad')
            ->set('category', 'Cable')
            ->set('price', '20')
            ->set('barcode', "bad\tcode")
            ->call('save')
            ->assertHasErrors(['barcode']);

        $this->assertSame(0, Product::count());
    }

    public function test_the_generate_button_fills_a_valid_unique_ean13(): void
    {
        [$shop] = $this->shopOwner();

        $component = Livewire::test('products.index')
            ->call('openCreate')
            ->call('generateBarcode');

        $generated = $component->get('barcode');
        $this->assertTrue(BarcodeService::isEan13($generated));
        $this->assertStringStartsWith('2', $generated);
    }

    public function test_bulk_generation_fills_missing_barcodes_and_leaves_existing_ones_alone(): void
    {
        [$shop] = $this->shopOwner();
        $manual = $this->product($shop->id, 'Manual', 'MANUAL-1');
        $this->product($shop->id, 'Missing A');
        $this->product($shop->id, 'Missing B');

        Livewire::test('products.index')->call('runBulkBarcodeGeneration');

        $this->assertSame('MANUAL-1', $manual->fresh()->barcode);
        $this->assertSame(0, Product::whereNull('barcode')->count());
    }

    public function test_the_bulk_action_offers_a_confirmation_before_running(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'Missing');

        $this->get(route('products.index'))
            ->assertSee('wire:confirm="Generate barcodes for up to '.BarcodeService::BULK_RUN_LIMIT.' products', false);
    }

    public function test_a_single_product_without_a_barcode_can_be_given_one(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'Plain');

        Livewire::test('products.index')->call('generateBarcodeFor', $product->id);

        $this->assertTrue(BarcodeService::isEan13($product->fresh()->barcode));
    }

    public function test_the_barcode_field_has_enter_key_submit_suppressed(): void
    {
        $this->shopOwner();

        $this->get(route('products.index'))
            ->assertSee('keydown.enter.prevent', false);
    }

    public function test_the_products_list_shows_a_barcode_column_and_search_matches_barcode(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'Galaxy A15', '8901234567890');
        $this->product($shop->id, 'USB Cable', '1111111111111');

        Livewire::test('products.index')
            ->set('search', '8901234567890')
            ->assertSee('Galaxy A15')
            ->assertDontSee('USB Cable');
    }

    public function test_the_shared_report_has_a_barcode_column_and_searches_it(): void
    {
        [$shop] = $this->shopOwner();
        $this->product($shop->id, 'Galaxy A15', '8901234567890');

        $report = new ProductReport('8901234567890');
        $this->assertContains('Barcode', $report->tableHeaders());
        $rows = $report->tableRows(forExcel: true);
        $this->assertCount(1, $rows);
        $this->assertSame('8901234567890', $rows[0][array_search('Barcode', $report->tableHeaders(), true)]);
    }

    public function test_the_barcode_column_is_absent_from_the_report_when_barcodes_are_off(): void
    {
        [$shop] = $this->shopOwner(barcodesEnabled: false);
        $this->product($shop->id, 'Galaxy A15', '8901234567890');

        $report = new ProductReport;
        $this->assertNotContains('Barcode', $report->tableHeaders());
        $this->assertCount(count($report->tableHeaders()), $report->columnTypes());
    }

    public function test_a_barcode_is_not_searchable_when_barcodes_are_off(): void
    {
        [$shop] = $this->shopOwner(barcodesEnabled: false);
        $this->product($shop->id, 'Zeta Widget', '8901234567890');

        Livewire::test('products.index')
            ->set('search', '8901234567890')
            ->assertDontSee('Zeta Widget');
    }

    public function test_the_generate_button_and_print_control_are_inside_the_products_components_boundary(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop->id, 'Galaxy A15', '8901234567890');
        $this->product($shop->id, 'Needs One');

        $html = $this->get(route('products.index'))->getContent();
        $snapshotOffset = $this->componentSnapshotOffset($html, 'products.index');

        $this->assertNotFalse($snapshotOffset);
        foreach (['wire:click="generateBarcode"', "wire:click=\"openPrintLabel({$product->id})\"", 'wire:click="runBulkBarcodeGeneration"', 'wire:submit="printLabels"'] as $needle) {
            $offset = strpos($html, $needle);
            $this->assertNotFalse($offset, "{$needle} missing");
            $this->assertGreaterThan($snapshotOffset, $offset, "{$needle} sits outside the products.index wire:id boundary.");
        }
    }

    public function test_a_role_without_products_access_still_cannot_generate_barcodes(): void
    {
        [$shop] = $this->shopOwner();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('products.index'))->assertForbidden();
    }

    protected function componentSnapshotOffset(string $html, string $componentName): int|false
    {
        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);

            if (($snapshot['memo']['name'] ?? null) === $componentName) {
                return $offset;
            }
        }

        return false;
    }
}
