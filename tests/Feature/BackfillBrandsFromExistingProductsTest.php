<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every distinct brand string already in use on a mobile product must get
 * its own Brand row so historical products keep showing up correctly in the
 * new dropdown/Settings list — the products themselves are never touched.
 */
class BackfillBrandsFromExistingProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function migration()
    {
        return require database_path('migrations/2026_09_19_090001_backfill_brands_from_existing_products.php');
    }

    public function test_a_distinct_existing_brand_value_gets_its_own_brand_row(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        Product::create([
            'shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15',
            'price' => 25000, 'stock_quantity' => 1,
            'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => null],
        ]);

        $this->migration()->up();

        $this->assertTrue(Brand::where('shop_id', $shop->id)->where('name', 'Samsung')->exists());
    }

    public function test_the_same_brand_across_multiple_products_only_creates_one_brand_row(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'A', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null]]);
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'B', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null]]);

        $this->migration()->up();

        $this->assertSame(1, Brand::where('shop_id', $shop->id)->where('name', 'Samsung')->count());
    }

    public function test_products_with_no_brand_or_a_blank_brand_are_skipped(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'stock_quantity' => 1, 'details' => ['category' => 'Cable']]);
        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Blank Brand Phone', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => '', 'model' => null, 'imei' => null]]);

        $this->migration()->up();

        $this->assertSame(0, Brand::count());
    }

    public function test_the_same_brand_name_in_different_shops_gets_a_row_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        Product::create(['shop_id' => $shopA->id, 'type' => 'mobile', 'name' => 'A', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null]]);
        Product::create(['shop_id' => $shopB->id, 'type' => 'mobile', 'name' => 'B', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null]]);

        $this->migration()->up();

        $this->assertSame(2, Brand::where('name', 'Samsung')->count());
        $this->assertTrue(Brand::where('shop_id', $shopA->id)->where('name', 'Samsung')->exists());
        $this->assertTrue(Brand::where('shop_id', $shopB->id)->where('name', 'Samsung')->exists());
    }

    public function test_existing_products_are_never_modified_by_the_backfill(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        $product = Product::create([
            'shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15',
            'price' => 25000, 'stock_quantity' => 1,
            'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => '12345'],
        ]);

        $before = $product->fresh()->toArray();

        $this->migration()->up();

        $this->assertSame($before, $product->fresh()->toArray());
    }

    public function test_running_the_migration_twice_does_not_duplicate_brand_rows(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'A', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null]]);

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertSame(1, Brand::where('shop_id', $shop->id)->where('name', 'Samsung')->count());
    }

    public function test_down_removes_the_backfilled_brand(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'A', 'price' => 1, 'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null]]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertFalse(Brand::where('shop_id', $shop->id)->where('name', 'Samsung')->exists());
    }
}
