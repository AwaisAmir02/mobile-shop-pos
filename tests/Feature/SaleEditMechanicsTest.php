<?php

namespace Tests\Feature;

use App\Actions\UpdateSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleEditMechanicsTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    public function test_increasing_a_line_quantity_decrements_more_stock(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 8, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 200, 'discount_amount' => 0, 'total' => 200]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 200]);

        UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount' => 0],
        ], 0.0, $customer->id, $owner->id);

        // product.stock_quantity started at 8 (not yet reduced, since this test seeds the SaleItem directly rather than through a real sale save); quantity 2 -> 5 is a delta of +3 to decrement.
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(500.0, (float) $sale->fresh()->total);
    }

    public function test_decreasing_a_line_quantity_restocks_the_difference(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 8, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount_amount' => 0, 'line_total' => 500]);

        UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 2, 'discount' => 0],
        ], 0.0, $customer->id, $owner->id);

        $this->assertSame(11, $product->fresh()->stock_quantity);
    }

    public function test_swapping_a_product_restocks_the_old_one_and_decrements_the_new_one(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $productA = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 8, 'details' => ['category' => 'Cable']]);
        $productB = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'B', 'price' => 50, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 200, 'discount_amount' => 0, 'total' => 200]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $productA->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 200]);

        UpdateSale::handle($sale, [
            ['product_id' => $productB->id, 'product_name' => 'B', 'product_type' => 'accessory', 'unit_price' => 50, 'list_price' => 50, 'quantity' => 3, 'discount' => 0],
        ], 0.0, $customer->id, $owner->id);

        $this->assertSame(10, $productA->fresh()->stock_quantity);
        $this->assertSame(7, $productB->fresh()->stock_quantity);
    }

    public function test_an_increase_beyond_available_stock_is_rejected(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 1, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        try {
            UpdateSale::handle($sale, [
                ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 100, 'discount' => 0],
            ], 0.0, $customer->id, $owner->id);
            $this->fail('Expected a ValidationException for insufficient stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }
    }

    public function test_reducing_the_total_below_what_is_already_paid_is_blocked(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount_amount' => 0, 'line_total' => 500]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 300, 'payment_date' => now()->toDateString()]);

        $this->expectException(ValidationException::class);

        UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 2, 'discount' => 0],
        ], 0.0, null, $owner->id);
    }

    public function test_removing_the_customer_while_a_due_balance_remains_is_blocked(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount_amount' => 0, 'line_total' => 500]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 200, 'payment_date' => now()->toDateString()]);

        $this->expectException(ValidationException::class);

        UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount' => 0],
        ], 0.0, null, $owner->id);
    }

    public function test_removing_the_customer_is_allowed_once_the_sale_is_fully_paid(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount_amount' => 0, 'line_total' => 500]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 500, 'payment_date' => now()->toDateString()]);

        $updated = UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 5, 'discount' => 0],
        ], 0.0, null, $owner->id);

        $this->assertNull($updated->customer_id);
    }

    public function test_invoice_number_never_changes_after_an_edit(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);
        $before = $sale->invoiceNumber();

        $updated = UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 2, 'discount' => 0],
        ], 0.0, $customer->id, $owner->id);

        $this->assertSame($before, $updated->invoiceNumber());
    }

    public function test_a_cancelled_sale_cannot_be_edited(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        \App\Actions\CancelSale::handle($sale, $owner->id, null);

        $this->expectException(\RuntimeException::class);
        UpdateSale::handle($sale->fresh(['items']), [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount' => 0],
        ], 0.0, null, $owner->id);
    }

    public function test_an_edit_writes_one_sale_revision_with_a_snapshot_of_the_previous_state(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        UpdateSale::handle($sale, [
            ['product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 2, 'discount' => 0],
        ], 0.0, $customer->id, $owner->id);

        $revision = \App\Models\SaleRevision::where('sale_id', $sale->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(\App\Enums\SaleRevisionAction::Edited, $revision->action);
        $this->assertSame(100.0, (float) $revision->snapshot['total']);
    }

    public function test_editing_a_sale_from_another_shop_is_rejected_via_the_route(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id, 'is_owner' => true]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);

        $saleB = Sale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shopB->id, 'sale_id' => $saleB->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        $this->actingAs($ownerA);

        $this->get(route('sales.edit', $saleB))->assertStatus(404);
    }
}
