<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\CustomerPayments;
use App\Enums\BusinessCategory;
use App\Enums\WholesaleTool;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\Support\CreditControl;
use App\Support\PriceTiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * What each wholesale trade gets and retail never does: the P2 tools per trade
 * (BusinessCategory::wholesaleTools), retail screens, register and invoice
 * unchanged, and each trade selling on credit at its trade price end to end.
 */
class WholesaleTradesTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_each_trade_gets_its_own_tools_and_retail_gets_none(): void
    {
        $this->actAsNewShop('distributor');
        $tools = fn (string $category) => array_map(fn (WholesaleTool $tool) => $tool->value, BusinessCategory::from($category)->wholesaleTools());

        $this->assertSame(['cheques', 'returns', 'bonus'], $tools('wholesaler'));
        $this->assertSame(['cheques', 'returns', 'bonus', 'contra'], $tools('trader'));
        $this->assertSame(['cheques', 'returns', 'bonus', 'orders', 'routes', 'schemes'], $tools('distributor'));
        $this->assertSame([], $tools('kiryana'));
        $this->assertSame([], $tools('mobile_electronics'));

        $this->get(route('dashboard'))->assertSee('Orders')->assertSee('Cheques')->assertSee('Schemes');
        $this->get(route('cheques.index'))->assertOk();
        $this->get(route('sales-orders.index'))->assertOk();
        $this->get(route('load-sheets.index'))->assertOk();
        $this->get(route('schemes.index'))->assertOk();
        $this->get(route('delivery-routes.index'))->assertOk();
        $this->get(route('purchase-returns.index'))->assertOk();
        $this->get(route('purchase-returns.create'))->assertOk();
        $this->get(route('sales-performance.index'))->assertOk();
        $this->get(route('sales-orders.create'))->assertOk();

        $this->actAsNewShop('wholesaler');
        $this->get(route('cheques.index'))->assertOk();
        $this->get(route('sales-orders.index'))->assertNotFound();
        $this->get(route('schemes.index'))->assertNotFound();

        $this->actAsNewShop('kiryana');
        foreach (['cheques.index', 'sales-orders.index', 'schemes.index', 'purchase-returns.index', 'delivery-routes.index', 'sales-performance.index', 'load-sheets.index'] as $name) {
            $this->get(route($name))->assertNotFound();
        }
        $this->get(route('dashboard'))->assertDontSee('Cheques')->assertDontSee('Schemes');
    }

    public function test_retail_shops_see_no_wholesale_fields_and_their_register_lines_are_unchanged(): void
    {
        $shop = Shop::create(['name' => 'Corner Kiryana', 'business_category' => 'kiryana']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Soap', 'price' => 50, 'cost_price' => 30, 'wholesale_price' => 40, 'stock_quantity' => 10, 'details' => ['category' => 'X']]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana']);

        $this->assertFalse($shop->isWholesale());
        $this->get(route('customers.index'))->assertOk()->assertDontSee('Business name')->assertDontSee('Credit limit');
        $this->get(route('customers.show', $customer))->assertOk()->assertDontSee('Receive Payment')->assertDontSee('Credit limit');
        $this->get(route('reports.index'))->assertOk()->assertDontSee('Dues aging');

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id);
        $line = $register->get('cart')[$product->id];
        $this->assertArrayNotHasKey('price_tier', $line);
        $this->assertSame('50', $line['list_price']);
        $register->call('completeSale')->assertHasNoErrors();

        $item = SaleItem::where('product_id', $product->id)->sole();
        $this->assertSame(['50.00', '50.00', null], [$item->unit_price, $item->list_price, $item->price_tier]);
        $this->assertNull(Sale::sole()->due_date);
    }

    public function test_a_retail_invoice_prints_no_customer_block(): void
    {
        $shop = Shop::create(['name' => 'Phones', 'business_category' => 'mobile_electronics']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana', 'business_name' => 'Should not print']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 300, 'cost_price' => 100, 'stock_quantity' => 5, 'details' => ['category' => 'X']]);
        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0, $customer->id, $owner->id, now()->toDateString(), 0, paymentMethod: 'cash');

        $html = view('pdf.invoice', ['sale' => $sale->fresh(['items', 'shop', 'user', 'payments'])])->render();

        $this->assertStringNotContainsString('Should not print', $html);
        $this->assertStringNotContainsString('Previous balance', $html);
    }

    public static function subTypes(): array
    {
        return [
            'wholesaler' => ['wholesaler', 'Wholesale price'],
            'trader' => ['trader', 'Wholesale rate'],
            'distributor' => ['distributor', 'Trade price'],
        ];
    }

    #[DataProvider('subTypes')]
    public function test_each_sub_type_sells_on_credit_at_its_trade_price_collects_and_ages_dues(string $category, string $label): void
    {
        $this->shop->update(['business_category' => $category]);
        $this->owner = $this->owner->fresh();
        $this->actingAs($this->owner);

        $this->assertSame($label, PriceTiers::label($this->shop->fresh()));
        $this->get(route('customers.index'))->assertOk()->assertSee('Business name');
        $this->get(route('products.index'))->assertOk();

        $product = $this->product();
        $customer = $this->customer(['payment_terms_days' => 7, 'credit_limit' => 5000, 'area' => 'Route 4']);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)
            ->assertSee($label)
            ->set('cart.'.$product->id.'.quantity', 20)->set('amountPaidNow', '300')->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame(['1800.00', '2026-11-27'], [$sale->total, $sale->due_date->toDateString()]);
        $this->assertSame(1500.0, CreditControl::creditUsed($customer));

        CustomerPayments::receiveAtCounter($customer, 1000, 'cash', null, $this->owner->id);
        $this->assertSame(500.0, $sale->fresh()->amountDue());

        $this->get(route('dues-aging.index', ['area' => 'Route 4']))->assertOk()->assertSee('Rs 500.00');
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Receive Payment')->assertSee('Rs 5,000.00');
    }

    /** A fresh shop of that trade, signed in as its owner. */
    protected function actAsNewShop(string $category): void
    {
        $shop = Shop::create(['name' => 'Al-Noor '.$category, 'business_category' => $category]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
    }
}
