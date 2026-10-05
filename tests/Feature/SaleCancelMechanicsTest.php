<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Enums\SaleRevisionAction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRevision;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleCancelMechanicsTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    public function test_cancelling_restores_exact_stock_for_every_line(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $productA = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);
        $productB = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'B', 'price' => 50, 'stock_quantity' => 2, 'details' => ['category' => 'Cable']]);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 300, 'discount_amount' => 0, 'total' => 300]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $productA->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 200]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $productB->id, 'product_name' => 'B', 'product_type' => 'accessory', 'unit_price' => 50, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 100]);

        CancelSale::handle($sale, $owner->id, 'customer changed mind');

        $this->assertSame(7, $productA->fresh()->stock_quantity);
        $this->assertSame(4, $productB->fresh()->stock_quantity);
    }

    public function test_cancelling_skips_and_reports_a_line_whose_product_was_deleted(): void
    {
        [$shop, $owner] = $this->shopOwner();

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => null, 'product_name' => 'Deleted Product', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        $result = CancelSale::handle($sale, $owner->id, null);

        $this->assertSame(['Deleted Product'], $result['skipped']);
        $this->assertSame([], $result['restocked']);
    }

    public function test_cancelling_sets_status_timestamp_actor_and_reason(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        CancelSale::handle($sale, $owner->id, 'duplicate order');

        $sale->refresh();
        $this->assertTrue($sale->isCancelled());
        $this->assertNotNull($sale->cancelled_at);
        $this->assertSame($owner->id, $sale->cancelled_by);
        $this->assertSame('duplicate order', $sale->cancel_reason);
    }

    public function test_cancelling_is_idempotent_and_rejects_a_second_cancel(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        CancelSale::handle($sale, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        CancelSale::handle($sale->fresh(['items']), $owner->id, null);
    }

    public function test_sale_payments_are_never_rewritten_by_cancellation(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 100, 'payment_date' => now()->toDateString()]);

        CancelSale::handle($sale, $owner->id, null);

        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame(100.0, (float) $sale->payments()->sum('amount'));
    }

    public function test_cancelling_writes_one_sale_revision_with_a_snapshot(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        CancelSale::handle($sale, $owner->id, 'oops');

        $revision = SaleRevision::where('sale_id', $sale->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(SaleRevisionAction::Cancelled, $revision->action);
        $this->assertSame('oops', $revision->reason);
        $this->assertSame(100.0, (float) $revision->snapshot['total']);
        $this->assertCount(1, $revision->snapshot['items']);
    }

    public function test_cancel_is_rejected_for_a_sale_from_another_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);

        $saleB = Sale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shopB->id, 'sale_id' => $saleB->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        $this->actingAs($ownerA);

        $this->get(route('sales.show', $saleB))->assertStatus(404);
    }

    public function test_cancel_double_click_race_is_safe_under_a_row_lock(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 5, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        CancelSale::handle($sale, $owner->id, null);

        try {
            CancelSale::handle($sale->fresh(['items']), $owner->id, null);
            $this->fail('Expected a second cancel to be rejected.');
        } catch (\RuntimeException) {
            // expected
        }

        // Stock was only ever restored once, not twice.
        $this->assertSame(6, $product->fresh()->stock_quantity);
    }
}
