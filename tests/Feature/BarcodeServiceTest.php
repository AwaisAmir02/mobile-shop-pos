<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Services\BarcodeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_known_ean13_check_digit_is_computed_correctly(): void
    {
        $this->assertSame(1, BarcodeService::checkDigitFor('400638133393'));
        $this->assertTrue(BarcodeService::isEan13('4006381333931'));
        $this->assertFalse(BarcodeService::isEan13('4006381333932'));
    }

    public function test_non_thirteen_digit_values_are_not_ean13(): void
    {
        $this->assertFalse(BarcodeService::isEan13('12345'));
        $this->assertFalse(BarcodeService::isEan13('ABC1234567890'));
    }

    public function test_generated_candidates_are_valid_internal_ean13_numbers(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = BarcodeService::candidate();

            $this->assertMatchesRegularExpression('/^2\d{12}$/', $code);
            $this->assertTrue(BarcodeService::isEan13($code), "Candidate {$code} failed its own check digit.");
        }
    }

    public function test_generate_unique_avoids_a_barcode_already_taken_in_the_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        $generated = BarcodeService::generateUniqueFor($shop->id);

        $this->assertTrue(BarcodeService::isEan13($generated));
        $this->assertFalse(Product::withoutGlobalScopes()->where('barcode', $generated)->exists());
    }

    public function test_the_database_rejects_a_duplicate_barcode_within_one_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 1, 'barcode' => '2000000000015', 'details' => []]);

        $this->expectException(QueryException::class);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'B', 'price' => 1, 'barcode' => '2000000000015', 'details' => []]);
    }

    public function test_the_same_barcode_is_allowed_in_two_different_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        Product::create(['shop_id' => $shopA->id, 'type' => 'accessory', 'name' => 'A', 'price' => 1, 'barcode' => '8901234567890', 'details' => []]);
        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'B', 'price' => 1, 'barcode' => '8901234567890', 'details' => []]);

        $this->assertSame(2, Product::withoutGlobalScopes()->where('barcode', '8901234567890')->count());
    }

    public function test_many_null_barcodes_are_allowed_in_one_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 1, 'details' => []]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'B', 'price' => 1, 'details' => []]);

        $this->assertSame(2, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('barcode')->count());
    }

    public function test_bulk_assignment_gives_every_product_a_unique_barcode(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        for ($i = 0; $i < 60; $i++) {
            Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => "P{$i}", 'price' => 1, 'details' => []]);
        }

        $result = BarcodeService::assignMissing($shop->id);

        $this->assertSame(60, $result['assigned']);
        $this->assertSame(0, $result['remaining']);

        $barcodes = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->pluck('barcode');
        $this->assertCount(60, $barcodes->filter()->unique());
    }

    public function test_bulk_assignment_is_capped_per_run_and_reports_what_remains(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        for ($i = 0; $i < 230; $i++) {
            Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => "P{$i}", 'price' => 1, 'details' => []]);
        }

        $result = BarcodeService::assignMissing($shop->id);

        $this->assertSame(BarcodeService::BULK_RUN_LIMIT, $result['assigned']);
        $this->assertSame(230 - BarcodeService::BULK_RUN_LIMIT, $result['remaining']);
    }

    public function test_bulk_assignment_never_touches_products_that_already_have_a_barcode(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $kept = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Kept', 'price' => 1, 'barcode' => 'MANUAL-1', 'details' => []]);

        BarcodeService::assignMissing($shop->id);

        $this->assertSame('MANUAL-1', $kept->fresh()->barcode);
    }

    public function test_bulk_assignment_does_not_touch_another_shops_products(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $other = Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'Other', 'price' => 1, 'details' => []]);

        BarcodeService::assignMissing($shopA->id);

        $this->assertNull($other->fresh()->barcode);
    }

    public function test_the_label_image_is_a_png_data_uri_for_both_ean13_and_free_text(): void
    {
        $ean = BarcodeService::imageDataUri('2000000000015');
        $text = BarcodeService::imageDataUri('ABC-123');

        $this->assertStringStartsWith('data:image/png;base64,', $ean);
        $this->assertStringStartsWith('data:image/png;base64,', $text);
    }

    public function test_printable_ascii_check_rejects_non_ascii_and_control_characters(): void
    {
        $this->assertTrue(BarcodeService::isPrintable('ABC-123 xyz'));
        $this->assertFalse(BarcodeService::isPrintable(''));
        $this->assertFalse(BarcodeService::isPrintable("bad\tcode"));
        $this->assertFalse(BarcodeService::isPrintable('بارکوڈ'));
    }
}
