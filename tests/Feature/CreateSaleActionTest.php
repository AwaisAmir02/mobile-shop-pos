<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Enums\SaleSource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CreateSaleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function batchedProduct(Shop $shop, User $owner): array
    {
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 300, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $old = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 5, 'total_cost' => 1500, 'payment_status' => 'paid', 'amount_paid' => 1500, 'stock_date' => '2026-01-01']);
        $new = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 5, 'cost_price' => 400, 'selling_price' => 700, 'quantity_remaining' => 5, 'total_cost' => 2000, 'payment_status' => 'paid', 'amount_paid' => 2000, 'stock_date' => '2026-01-10']);

        return [$product, $old, $new];
    }

    public function test_pos_checkout_still_defaults_to_in_store_source(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        [$product] = $this->batchedProduct($shop, $owner);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 2)
            ->call('completeSale')
            ->assertHasNoErrors();

        $this->assertSame(SaleSource::InStore, Sale::first()->fresh()->source);
    }

    public function test_it_creates_a_split_fifo_sale_for_an_explicit_shop_with_no_logged_in_user(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        [$product, $old, $new] = $this->batchedProduct($shop, $owner);

        $this->assertGuest();

        $sale = CreateSale::handle(
            lines: [['product_id' => $product->id, 'quantity' => 7, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0,
            customerId: null,
            userId: $owner->id,
            saleDate: now()->toDateString(),
            amountPaid: 3900,
            shopId: $shop->id,
            attributes: ['source' => SaleSource::WhatsApp->value],
        );

        $sale = Sale::withoutGlobalScopes()->findOrFail($sale->id);
        $items = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->orderBy('id')->get();

        $this->assertSame($shop->id, $sale->shop_id);
        $this->assertSame(SaleSource::WhatsApp, $sale->source);
        $this->assertSame(3900.0, (float) $sale->total);
        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn ($item) => $item->shop_id === $shop->id));
        $this->assertSame([$old->id, $new->id], $items->pluck('stock_in_id')->all());
        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(3, $new->fresh()->quantity_remaining);
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame($shop->id, SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->value('shop_id'));
    }

    public function test_insufficient_stock_rolls_back_and_is_keyed_by_product(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        [$product, $old] = $this->batchedProduct($shop, $owner);

        try {
            CreateSale::handle([['product_id' => $product->id, 'quantity' => 11, 'discount' => 0.0, 'custom_price' => null]], 0, null, $owner->id, now()->toDateString(), 0, $shop->id);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey("lines.{$product->id}", $e->errors());
        }

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(5, $old->fresh()->quantity_remaining);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_a_product_from_another_shop_cannot_be_sold_when_shop_is_explicit(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id, 'is_owner' => true]);
        [$productB] = $this->batchedProduct($shopB, $ownerB);

        $this->expectException(ModelNotFoundException::class);

        CreateSale::handle([['product_id' => $productB->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0, null, null, now()->toDateString(), 0, $shopA->id);
    }
}
