<?php

namespace Tests\Feature;

use App\Actions\Buybacks;
use App\Actions\CancelStockIn;
use App\Actions\CreateSale;
use App\Actions\UpdateStockIn;
use App\Enums\RecordStatus;
use App\Models\Brand;
use App\Models\Buyback;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\SaleItem;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\User;
use App\Support\MoneyFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BuybackTest extends TestCase
{
    use RefreshDatabase;

    /** A valid IMEI (Luhn). */
    protected const IMEI = '356938035643809';

    protected Shop $shop;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Mobile Zone', 'business_category' => 'mobile_electronics']);
        MainCategory::ensureDefaultsExist($this->shop->id);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->actingAs($this->owner);
    }

    protected function data(array $overrides = []): array
    {
        return $overrides + [
            'seller_name' => 'Kamran', 'seller_phone' => '03001234567', 'seller_cnic' => '3520212345671',
            'brand' => 'Samsung', 'model' => 'Galaxy A54', 'imei' => '35-693803-564380-9', 'condition' => 'good',
            'battery_health' => 88, 'pta_approved' => true, 'has_box' => true, 'has_charger' => false,
            'price' => 45000, 'selling_price' => 52000, 'bought_on' => now()->toDateString(), 'note' => null,
        ];
    }

    protected function buy(array $overrides = [], ?string $method = 'cash', ?int $accountId = null): Buyback
    {
        return Buybacks::record($this->shop->fresh(), $this->data($overrides), $this->owner->id, $method, $accountId);
    }

    protected function sellPhone(Product $product): void
    {
        CreateSale::handle(
            lines: [['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0, customerId: null, userId: $this->owner->id, saleDate: now()->toDateString(), amountPaid: 52000,
        );
    }

    public function test_a_buyback_brings_the_phone_into_stock_as_a_paid_vendorless_batch(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Kamran', 'phone' => '03001234567']);

        $buyback = $this->buy();
        $product = Product::findOrFail($buyback->product_id);
        $batch = StockIn::findOrFail($buyback->stock_in_id);

        $this->assertSame('mobile', $product->type);
        $this->assertSame('Samsung Galaxy A54 (Used)', $product->name);
        $this->assertSame(self::IMEI, $product->details['imei']);
        $this->assertSame('good', $product->details['condition']);
        $this->assertSame('Samsung', $product->details['brand']);
        $this->assertEquals(1, $product->stock_quantity);
        $this->assertSame('52000.00', $product->price);
        $this->assertSame('45000.00', $product->cost_price);

        $this->assertNull($batch->vendor_id);
        $this->assertEquals(1, $batch->quantity);
        $this->assertSame('45000.00', $batch->cost_price);
        $this->assertSame('paid', $batch->payment_status->value);
        $this->assertSame(1, $batch->payments()->count());
        $this->assertSame('cash', $batch->payments()->first()->payment_method);

        $this->assertSame('35202-1234567-1', $buyback->seller_cnic);
        $this->assertSame($customer->id, $buyback->customer_id);
        $this->assertTrue(Brand::where('name', 'Samsung')->exists(), 'kept editable on the Products screen');

        // Cash & Balances sees the money going out of the drawer.
        $out = (new MoneyFlow($this->shop->id))->movements()->where('source', 'vendor')->sum('amount');
        $this->assertEquals(-45000, $out);
    }

    public function test_it_can_be_paid_from_a_shop_account(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);

        $buyback = $this->buy([], 'account', $account->id);

        $payment = StockIn::findOrFail($buyback->stock_in_id)->payments()->first();
        $this->assertSame($account->id, $payment->shop_account_id);
    }

    public function test_reselling_it_costs_the_price_paid(): void
    {
        $buyback = $this->buy();

        $this->sellPhone(Product::findOrFail($buyback->product_id));

        $item = SaleItem::where('product_id', $buyback->product_id)->sole();
        $this->assertEquals(45000, (float) $item->cost_price);
        $this->assertTrue($buyback->fresh()->isSold());

        $this->expectException(ValidationException::class);
        Buybacks::cancel($buyback, $this->owner->id);
    }

    public function test_cancelling_an_unsold_buyback_takes_it_out_of_stock_and_reverses_the_payment(): void
    {
        $buyback = $this->buy();

        Buybacks::cancel($buyback, $this->owner->id, 'Wrong phone');

        $buyback = Buyback::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($buyback->id);
        $product = Product::withoutGlobalScopes()->findOrFail($buyback->product_id);
        $batch = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($buyback->stock_in_id);

        $this->assertSame(RecordStatus::Cancelled, $buyback->status);
        $this->assertSame('Wrong phone', $buyback->cancel_reason);
        $this->assertSame(RecordStatus::Cancelled, $batch->status);
        $this->assertEquals(0, $product->stock_quantity);
        $this->assertNotNull($product->archived_at);
        $this->assertEquals(0, (new MoneyFlow($this->shop->id))->movements()->where('source', 'vendor')->sum('amount'));

        $this->expectException(ValidationException::class);
        Buybacks::cancel($buyback, $this->owner->id);
    }

    public function test_the_same_phone_can_be_bought_again_after_a_cancel(): void
    {
        $first = $this->buy();
        Buybacks::cancel($first, $this->owner->id);

        $again = $this->buy(['price' => 40000]);

        $this->assertSame($first->product_id, $again->product_id);
        $this->assertNull(Product::findOrFail($again->product_id)->archived_at);
        $this->assertEquals(1, Product::findOrFail($again->product_id)->stock_quantity);
    }

    public function test_a_phone_still_in_stock_or_with_a_bad_imei_is_refused(): void
    {
        $this->buy();

        foreach ([[], ['imei' => '356938035643808']] as $overrides) {
            try {
                $this->buy($overrides);
                $this->fail('Expected a refusal.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('imei', $e->errors());
            }
        }

        $this->assertSame(1, Buyback::count());
        $this->assertSame(1, StockIn::count());
    }

    public function test_buying_back_a_phone_the_shop_sold_restocks_its_product_and_a_cancel_restores_it(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id, 'type' => 'mobile', 'name' => 'Galaxy A54 New', 'price' => 70000, 'cost_price' => 62000,
            'stock_quantity' => 0, 'details' => ['brand' => 'Samsung', 'model' => 'Galaxy A54', 'imei' => self::IMEI, 'category' => null],
        ]);

        $buyback = $this->buy();

        $this->assertSame($product->id, $buyback->product_id);
        $this->assertTrue($buyback->reused_product);
        $this->assertSame('Samsung Galaxy A54 (Used)', $product->fresh()->name);
        $this->assertEquals(1, $product->fresh()->stock_quantity);
        $this->assertSame(1, Product::withoutGlobalScopes()->count());

        Buybacks::cancel($buyback, $this->owner->id);

        $product->refresh();
        $this->assertSame('Galaxy A54 New', $product->name);
        $this->assertSame('70000.00', $product->price);
        $this->assertNull($product->archived_at);
        $this->assertArrayNotHasKey('condition', $product->details);
        $this->assertEquals(0, $product->stock_quantity);
    }

    public function test_its_batch_cannot_be_edited_or_cancelled_from_stock_in(): void
    {
        $buyback = $this->buy();
        $batch = StockIn::findOrFail($buyback->stock_in_id);

        foreach ([
            fn () => CancelStockIn::handle($batch, $this->owner->id),
            fn () => UpdateStockIn::handle($batch, ['quantity' => 1, 'cost_price' => 1000, 'selling_price' => 2000, 'vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null], $this->owner->id),
        ] as $action) {
            try {
                $action();
                $this->fail('Expected a refusal.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('used phone', implode(' ', collect($e->errors())->flatten()->all()));
            }
        }

        $this->assertSame('45000.00', $batch->fresh()->cost_price);
        $this->assertSame(RecordStatus::Active, $batch->fresh()->status);
    }

    public function test_the_screen_records_a_buyback_and_lists_it(): void
    {
        Livewire::test('buybacks.index')
            ->set('seller_name', 'Kamran')
            ->set('seller_cnic', '35202-1234567-1')
            ->set('brand', 'Apple')
            ->set('model', 'iPhone 12')
            ->set('imei', self::IMEI)
            ->set('condition', 'like_new')
            ->set('pta', 'no')
            ->set('price', '80000')
            ->set('selling_price', '92000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Apple iPhone 12')
            ->assertSee('Not PTA')
            ->assertSee('In stock');

        $buyback = Buyback::sole();
        $this->assertFalse($buyback->pta_approved);
        $this->assertSame('like_new', $buyback->condition->value);

        Livewire::test('buybacks.index')->call('cancel', $buyback->id);
        $this->assertTrue(Buyback::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($buyback->id)->isCancelled());
    }

    public function test_the_screen_validates_the_cnic_and_price(): void
    {
        Livewire::test('buybacks.index')
            ->set('seller_name', 'Kamran')
            ->set('seller_cnic', '12345')
            ->set('brand', 'Apple')
            ->set('price', '0')
            ->set('selling_price', '92000')
            ->call('save')
            ->assertHasErrors(['seller_cnic', 'price']);

        $this->assertSame(0, Buyback::count());
    }

    public function test_only_mobile_and_electronics_shops_and_legacy_shops_get_it(): void
    {
        $this->get(route('buybacks.index'))->assertOk();
        $this->get(route('dashboard'))->assertSee('Used Phone Buyback');

        $legacy = Shop::create(['name' => 'Old Shop', 'business_category' => null]);
        $this->actingAs(User::factory()->create(['shop_id' => $legacy->id, 'is_owner' => true]))
            ->get(route('buybacks.index'))->assertOk();

        $clothing = Shop::create(['name' => 'Kapra', 'business_category' => 'clothing']);
        $this->actingAs(User::factory()->create(['shop_id' => $clothing->id, 'is_owner' => true]));
        $this->get(route('buybacks.index'))->assertNotFound();
        $this->get(route('dashboard'))->assertDontSee('Used Phone Buyback');

        $this->expectException(ValidationException::class);
        Buybacks::record($clothing, $this->data(), null, 'cash', null);
    }

    public function test_staff_need_the_buyback_permission_and_its_cancel_permission(): void
    {
        $buyback = $this->buy();

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Counter', 'permissions' => ['dashboard', 'buybacks']]);
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('buybacks.index'))->assertOk();
        Livewire::test('buybacks.index')->call('cancel', $buyback->id)->assertForbidden();
        $this->assertFalse($buyback->fresh()->isCancelled());

        $role->update(['permissions' => ['dashboard']]);
        $this->actingAs($staff->fresh())->get(route('buybacks.index'))->assertForbidden();
    }

    public function test_admin_module_row_is_unavailable_for_a_shop_that_does_not_sell_devices(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $clothing = Shop::create(['name' => 'Kapra', 'business_category' => 'clothing']);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $clothing])
            ->assertSeeHtml('data-module-unavailable="buybacks"')
            ->assertSee('Only for Mobile & Electronics shops');

        Livewire::test('admin.shops.show', ['shop' => $this->shop])
            ->assertDontSeeHtml('data-module-unavailable="buybacks"')
            ->call('toggleModule', 'buybacks');

        $this->assertContains('buybacks', $this->shop->fresh()->disabled_screens);
        $this->actingAs($this->owner->fresh())->get(route('buybacks.index'))->assertForbidden();
    }
}
