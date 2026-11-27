<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Enums\PriceTier;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\PriceTiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * Trade prices (W3, PriceTiers): the customer's level on pieces, packs and outer
 * packs, in the register and the order form alike; a typed price still wins.
 */
class TradePricesTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_the_register_prices_a_customer_at_the_trade_price_and_walk_ins_at_the_normal_price(): void
    {
        $product = $this->product();
        $trade = $this->customer();
        $normal = $this->customer(['name' => 'Walk-in regular', 'business_name' => null, 'price_tier' => PriceTier::Normal]);

        $register = Livewire::test('sales.create')->call('addToCart', $product->id);
        $this->assertSame('100', $register->get('cart')[$product->id]['list_price']);

        $register->set('customerId', (string) $trade->id);
        $this->assertSame('90', $register->get('cart')[$product->id]['list_price']);
        $this->assertSame('trade', $register->get('cart')[$product->id]['price_tier']);

        $register->set('customerId', (string) $normal->id);
        $this->assertSame('100', $register->get('cart')[$product->id]['list_price']);

        $register->set('customerId', (string) $trade->id)->set('cart.'.$product->id.'.quantity', 10)->call('completeSale')->assertHasNoErrors();

        $item = SaleItem::sole();
        $this->assertSame(['90.00', '90.00', PriceTier::Trade, '900.00'], [$item->unit_price, $item->list_price, $item->price_tier, $item->line_total]);
        $this->assertFalse($item->wasPriceOverridden());
        $this->assertSame('Wholesale price', PriceTiers::label($this->shop));
    }

    public function test_a_manual_price_still_wins_over_the_trade_price(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('cart.'.$product->id.'.custom_price', '85')
            ->set('customerId', (string) $customer->id)
            ->call('completeSale')
            ->assertHasNoErrors();

        $item = SaleItem::sole();
        $this->assertSame(['85.00', '90.00'], [$item->unit_price, $item->list_price]);
        $this->assertTrue($item->wasPriceOverridden());
    }

    public function test_a_carton_sells_at_the_trade_price_but_never_above_its_own_price(): void
    {
        $product = $this->product(['pack_name' => 'Carton', 'pack_size' => 12, 'pack_price' => 1100]);
        $customer = $this->customer();

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->call('sellAs', $product->id, 'pack');
        // 12 x 90 = 1,080 is under the carton's own 1,100.
        $this->assertSame('1080', $register->get('cart')[$product->id]['list_price']);
        $register->set('cart.'.$product->id.'.quantity', 2)->call('completeSale')->assertHasNoErrors();
        $this->assertSame('2160.00', Sale::sole()->total);
        $this->assertSame(['Carton', 12], [SaleItem::sole()->pack_name, SaleItem::sole()->pack_size]);

        $cheapCarton = $this->product(['name' => 'Oil', 'pack_name' => 'Carton', 'pack_size' => 12, 'pack_price' => 1000]);
        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $cheapCarton->id)->call('sellAs', $cheapCarton->id, 'pack');
        $this->assertSame('1000', $register->get('cart')[$cheapCarton->id]['list_price']);
    }

    public function test_the_order_form_prices_pieces_packs_and_outer_packs_like_the_register(): void
    {
        $this->shop->update(['business_category' => 'distributor']);
        $this->actingAs($this->owner = $this->owner->fresh());
        // Trade 90 a piece: a box of 12 is 1,080 (under its own 1,100); a carton of 6 boxes is 6,480 (under its own 7,000).
        $product = $this->product(['pack_name' => 'Box', 'pack_size' => 12, 'pack_price' => 1100, 'outer_pack_name' => 'Carton', 'outer_pack_size' => 6, 'outer_pack_price' => 7000]);
        $customer = $this->customer();

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id);
        $registerPrices = ['piece' => $register->get('cart')[$product->id]['list_price']];
        $registerPrices['pack'] = $register->call('sellAs', $product->id, 'pack')->get('cart')[$product->id]['list_price'];
        $registerPrices['outer'] = $register->call('sellAs', $product->id, 'outer')->get('cart')[$product->id]['list_price'];
        $this->assertSame(['piece' => '90', 'pack' => '1080', 'outer' => '6480'], $registerPrices);

        $form = Livewire::test('sales-orders.form')->set('customerId', (string) $customer->id)->call('add', $product->id);
        $formPrices = ['pack' => $form->get('lines')[0]['unit_price']];   // a product with a pack starts in packs
        $formPrices['outer'] = $form->call('sellAs', 0, 'outer')->get('lines')[0]['unit_price'];
        $formPrices['piece'] = $form->call('sellAs', 0, 'piece')->get('lines')[0]['unit_price'];
        $this->assertEquals($registerPrices, $formPrices);

        $form->call('sellAs', 0, 'outer')->set('lines.0.quantity', '2')->call('save')->assertHasNoErrors();

        $line = SalesOrderLine::sole();
        $this->assertSame([144, '90.00', PriceTier::Trade, 'Carton', 72], [(int) $line->quantity, $line->unit_price, $line->price_tier, $line->pack_name, $line->pack_size]);
        $this->assertSame('12960.00', SalesOrder::sole()->estimated_total);
    }

    public function test_the_register_shows_the_last_rate_this_customer_paid_and_can_use_it(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        CreateSale::handle([['product_id' => $product->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => 87.5]], 0, $customer->id, $this->owner->id, '2026-11-12', 175, paymentMethod: 'cash');

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id);
        $register->assertSee('Last rate')->assertSee('Rs 87.50')->assertSee('12 Nov 2026');

        $register->call('useLastRate', $product->id);
        $this->assertSame('87.5', (string) $register->get('cart')[$product->id]['custom_price']);
    }

    public function test_editing_a_trade_sale_keeps_its_trade_price(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('cart.'.$product->id.'.quantity', 3)->set('amountPaidNow', '0')->call('completeSale')->assertHasNoErrors();
        $sale = Sale::sole();

        Livewire::test('sales.edit', ['sale' => $sale])->set('cart.'.$product->id.'.quantity', 4)->call('saveChanges')->assertHasNoErrors();

        $item = SaleItem::where('sale_id', $sale->id)->sole();
        $this->assertSame(['90.00', '90.00', PriceTier::Trade, '360.00'], [$item->unit_price, $item->list_price, $item->price_tier, $item->line_total]);
    }
}
