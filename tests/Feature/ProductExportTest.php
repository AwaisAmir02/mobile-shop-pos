<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\ProductReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Products didn't have an export at all before — added using the exact
 * same shared TableExportService/export-dropdown mechanism as the other
 * four screens, respecting whichever of the screen's own real filters
 * (search, Main Category) are currently active. Row/summary building
 * lives in App\ReportQueries\ProductReport (shared with the Reports
 * screen) rather than on the products.index component itself.
 */
class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_respects_the_search_filter(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 200, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Power Bank', 'price' => 3000, 'stock_quantity' => 5, 'details' => ['category' => 'Power Bank']]);

        $this->actingAs($owner);

        $rows = (new ProductReport(search: 'Cable'))->tableRows(forExcel: false);

        $this->assertCount(1, $rows);
        $this->assertSame('USB Cable', $rows[0][0]);
    }

    public function test_export_respects_the_main_category_filter(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15', 'price' => 25000, 'stock_quantity' => 3, 'details' => ['brand' => $brand->name, 'model' => 'A15', 'imei' => null]]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 200, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);

        $this->actingAs($owner);

        $rows = (new ProductReport(typeFilter: 'mobile'))->tableRows(forExcel: false);

        $this->assertCount(1, $rows);
        $this->assertSame('Galaxy A15', $rows[0][0]);
    }

    public function test_export_with_no_filter_includes_everything(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 200, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Power Bank', 'price' => 3000, 'stock_quantity' => 5, 'details' => ['category' => 'Power Bank']]);

        $this->actingAs($owner);

        $report = new ProductReport;
        $rows = $report->tableRows(forExcel: false);

        $this->assertCount(2, $rows);
        $this->assertSame('All records', $report->filtersSummary());
    }

    public function test_exporting_to_pdf_and_excel_produces_a_downloadable_response(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 200, 'cost_price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);

        $this->actingAs($owner);

        Excel::fake();

        Livewire::test('products.index')->call('exportPdf')->assertStatus(200);

        Livewire::test('products.index')->call('exportExcel');

        Excel::matchByRegex();
        Excel::assertDownloaded(
            '/^products-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/',
            fn ($export) => count($export->array()) === 7 // 5 meta/header rows + spacer + 1 data row... see TableExport for the exact layout
        );
    }

    public function test_a_product_with_no_cost_price_exports_a_blank_cost_price_cell_not_an_error(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 200, 'cost_price' => null, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);

        $this->actingAs($owner);

        $rows = (new ProductReport)->tableRows(forExcel: true);

        $this->assertNull($rows[0][4]);
    }

    public function test_a_shop_can_only_export_its_own_products(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        Product::create(['shop_id' => $shopA->id, 'type' => 'accessory', 'name' => 'Shop A Cable', 'price' => 200, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'Shop B Cable', 'price' => 999, 'stock_quantity' => 1, 'details' => ['category' => 'Cable']]);

        $this->actingAs($ownerA);

        $rows = (new ProductReport)->tableRows(forExcel: false);

        $this->assertCount(1, $rows);
        $this->assertSame('Shop A Cable', $rows[0][0]);
    }

    public function test_a_role_without_products_access_cannot_reach_products_to_export(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => []]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('products.index'))->assertForbidden();
    }
}
