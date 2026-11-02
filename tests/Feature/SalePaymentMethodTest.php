<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\CreateSale;
use App\Actions\RecordSalePayment;
use App\Enums\SalePaymentMethod;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SalePaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithOwner(string $name = 'Shop A'): array
    {
        $shop = Shop::create(['name' => $name, 'whatsapp_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);

        return [$shop, $owner];
    }

    protected function product(Shop $shop, float $price = 1000, int $stock = 10): Product
    {
        return Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'Cable',
            'price' => $price,
            'stock_quantity' => $stock,
            'details' => [],
        ]);
    }

    protected function account(Shop $shop, string $name = 'Meezan Bank'): ShopAccount
    {
        return ShopAccount::create(['shop_id' => $shop->id, 'name' => $name, 'provider_type' => 'Bank']);
    }

    protected function line(Product $product, int $quantity = 1): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null];
    }

    protected function partlyPaidSale(Shop $shop, User $owner, Customer $customer): Sale
    {
        $this->actingAs($owner);

        return CreateSale::handle([$this->line($this->product($shop))], 0, $customer->id, $owner->id, now()->toDateString(), 200);
    }

    protected function pendingOrder(Shop $shop, Product $product, int $quantity = 2): WhatsAppOrder
    {
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923001234567', 'profile_name' => 'Ali', 'last_inbound_at' => now()]);

        $order = WhatsAppOrder::create([
            'shop_id' => $shop->id,
            'whatsapp_contact_id' => $contact->id,
            'status' => WhatsAppOrderStatus::Pending,
            'submitted_at' => now(),
        ]);

        WhatsAppOrderItem::create([
            'shop_id' => $shop->id,
            'whatsapp_order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'quoted_unit_price' => $product->price,
        ]);

        $order->recalculateQuotedTotal();

        return $order;
    }

    // ── The shared rule ───────────────────────────────────────────────

    public function test_payment_attributes_follow_the_agreed_rules(): void
    {
        [$shop] = $this->shopWithOwner();
        [$otherShop] = $this->shopWithOwner('Shop B');
        $account = $this->account($shop);
        $foreign = $this->account($otherShop, 'Other Bank');

        $none = ['payment_method' => null, 'shop_account_id' => null];

        $this->assertSame($none, SalePaymentMethod::paymentAttributes(null, null, $shop->id, 500));
        $this->assertSame($none, SalePaymentMethod::paymentAttributes('', null, $shop->id, 500));
        $this->assertSame($none, SalePaymentMethod::paymentAttributes('account', $account->id, $shop->id, 0));
        $this->assertSame($none, SalePaymentMethod::paymentAttributes('cash', null, $shop->id, 0.001));
        $this->assertSame(['payment_method' => 'cash', 'shop_account_id' => null], SalePaymentMethod::paymentAttributes('cash', $account->id, $shop->id, 500));
        $this->assertSame(['payment_method' => 'account', 'shop_account_id' => $account->id], SalePaymentMethod::paymentAttributes('account', $account->id, $shop->id, 500));

        // Logged in as the *other* shop, so the auth-based ShopScope would see
        // its account — only the explicit $shopId check rejects it.
        $this->actingAs(User::factory()->create(['shop_id' => $otherShop->id]));

        foreach ([['account', $foreign->id], ['account', null], ['account', 999999], ['cheque', null]] as [$method, $accountId]) {
            try {
                SalePaymentMethod::paymentAttributes($method, $accountId, $shop->id, 500);
                $this->fail("Expected a validation error for {$method} / ".var_export($accountId, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('paymentMethod', $e->errors());
            }
        }
    }

    // ── Each writer saves the method ──────────────────────────────────

    public function test_sales_create_defaults_to_cash_and_saves_the_picked_account(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $account = $this->account($shop);
        $product = $this->product($shop);
        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->assertSet('paymentMethod', 'cash')
            ->call('addToCart', $product->id)
            ->call('completeSale')
            ->assertHasNoErrors();

        $payment = SalePayment::latest('id')->firstOrFail();
        $this->assertSame(SalePaymentMethod::Cash, $payment->payment_method);
        $this->assertNull($payment->shop_account_id);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('paymentMethod', (string) $account->id)
            ->call('completeSale')
            ->assertHasNoErrors();

        $payment = SalePayment::latest('id')->firstOrFail();
        $this->assertSame(SalePaymentMethod::Account, $payment->payment_method);
        $this->assertSame($account->id, $payment->shop_account_id);
        $this->assertSame('Meezan Bank · Bank', $payment->methodLabel());
    }

    public function test_record_payment_modal_defaults_to_not_recorded_and_saves_the_method(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $account = $this->account($shop);
        $sale = $this->partlyPaidSale($shop, $owner, $customer);

        $component = Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->assertSet('paymentMethod', '')
            ->set('paymentAmount', '300')
            ->set('paymentMethod', (string) $account->id)
            ->call('submitPayment')
            ->assertHasNoErrors();

        $payment = SalePayment::latest('id')->firstOrFail();
        $this->assertSame(300.0, (float) $payment->amount);
        $this->assertSame($owner->id, $payment->user_id);
        $this->assertSame($account->id, $payment->shop_account_id);

        $component->call('openRecordPayment', $sale->id)
            ->set('paymentAmount', '100')
            ->set('paymentMethod', 'cash')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $this->assertSame(SalePaymentMethod::Cash, SalePayment::latest('id')->firstOrFail()->payment_method);
    }

    public function test_udhaar_settle_due_saves_the_method_for_sale_dues(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $account = $this->account($shop);
        $sale = $this->partlyPaidSale($shop, $owner, $customer);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'sale', $sale->id)
            ->assertSet('paymentMethod', '')
            ->set('dueSettleAmount', '250')
            ->set('paymentMethod', (string) $account->id)
            ->call('submitDueSettlement')
            ->assertHasNoErrors();

        $payment = SalePayment::latest('id')->firstOrFail();
        $this->assertSame(250.0, (float) $payment->amount);
        $this->assertSame(SalePaymentMethod::Account, $payment->payment_method);
        $this->assertSame($account->id, $payment->shop_account_id);
    }

    public function test_whatsapp_accept_form_defaults_to_not_recorded_and_saves_the_method(): void
    {
        Bus::fake();
        [$shop, $owner] = $this->shopWithOwner();
        $account = $this->account($shop);
        $order = $this->pendingOrder($shop, $this->product($shop));
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->assertSet('paymentMethod', '')
            ->set('paymentMethod', (string) $account->id)
            ->call('accept')
            ->assertHasNoErrors();

        $payment = Sale::sole()->payments()->sole();
        $this->assertSame(2000.0, (float) $payment->amount);
        $this->assertSame(SalePaymentMethod::Account, $payment->payment_method);
        $this->assertSame($account->id, $payment->shop_account_id);
    }

    // ── 0-amount rows, empty method, and money figures ────────────────

    public function test_a_zero_amount_payment_stores_no_method_even_when_one_was_chosen(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $account = $this->account($shop);
        $this->actingAs($owner);

        $sale = CreateSale::handle([$this->line($this->product($shop))], 0, $customer->id, $owner->id, now()->toDateString(), 0, paymentMethod: 'account', shopAccountId: $account->id);

        $payment = $sale->payments()->sole();
        $this->assertSame(0.0, (float) $payment->amount);
        $this->assertNull($payment->payment_method);
        $this->assertNull($payment->shop_account_id);
    }

    public function test_an_empty_method_writes_exactly_the_same_row_as_before(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $this->actingAs($owner);

        $sale = CreateSale::handle([$this->line($this->product($shop))], 0, $customer->id, $owner->id, now()->toDateString(), 400);
        RecordSalePayment::handle($sale, 100, $owner->id);

        foreach ($sale->payments()->orderBy('id')->get() as $payment) {
            $this->assertSame($shop->id, $payment->shop_id);
            $this->assertSame($owner->id, $payment->user_id);
            $this->assertSame(now()->toDateString(), $payment->payment_date->toDateString());
            $this->assertNull($payment->payment_method);
            $this->assertNull($payment->shop_account_id);
            $this->assertSame('Not recorded', $payment->methodLabel());
        }

        $this->assertSame([400.0, 100.0], $sale->payments()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
    }

    public function test_amount_due_and_payment_status_do_not_depend_on_the_method(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $account = $this->account($shop);
        $this->actingAs($owner);

        $figures = [];

        foreach ([[null, null], ['cash', null], ['account', $account->id]] as [$method, $accountId]) {
            $sale = CreateSale::handle([$this->line($this->product($shop))], 0, $customer->id, $owner->id, now()->toDateString(), 300, paymentMethod: $method, shopAccountId: $accountId);
            $first = [$sale->amountPaid(), $sale->amountDue(), $sale->paymentStatus()];

            RecordSalePayment::handle($sale, 700, $owner->id, $method, $accountId);
            $sale->refresh();

            $figures[] = [$first, [$sale->amountPaid(), $sale->amountDue(), $sale->paymentStatus()]];
        }

        $this->assertSame([[300.0, 700.0, 'partial'], [1000.0, 0.0, 'paid']], $figures[0]);
        $this->assertSame($figures[0], $figures[1]);
        $this->assertSame($figures[0], $figures[2]);
        $this->assertSame(0.0, $customer->salesOutstandingBalance());
    }

    // ── Another shop's account ────────────────────────────────────────

    public function test_another_shops_account_is_rejected_by_every_writer_and_nothing_is_saved(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        [$otherShop] = $this->shopWithOwner('Shop B');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $foreign = $this->account($otherShop, 'Other Bank');
        $product = $this->product($shop);
        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('paymentMethod', (string) $foreign->id)
            ->call('completeSale')
            ->assertHasErrors(['paymentMethod']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $sale = $this->partlyPaidSale($shop, $owner, $customer);
        $paymentsBefore = SalePayment::count();

        Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->set('paymentAmount', '100')
            ->set('paymentMethod', (string) $foreign->id)
            ->call('submitPayment')
            ->assertHasErrors(['paymentMethod']);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'sale', $sale->id)
            ->set('dueSettleAmount', '100')
            ->set('paymentMethod', (string) $foreign->id)
            ->call('submitDueSettlement')
            ->assertHasErrors(['paymentMethod']);

        $this->assertSame($paymentsBefore, SalePayment::count());
    }

    public function test_accept_whatsapp_order_rejects_another_shops_account_with_an_explicit_shop_id(): void
    {
        Bus::fake();
        [$shop, $owner] = $this->shopWithOwner();
        [$otherShop] = $this->shopWithOwner('Shop B');
        $foreign = $this->account($otherShop, 'Other Bank');
        $own = $this->account($shop);
        $product = $this->product($shop);
        $order = $this->pendingOrder($shop, $product);
        $lines = [['product_id' => $product->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => 1000.0]];

        $this->actingAs($owner);

        try {
            AcceptWhatsAppOrder::handle($order, $lines, 0, null, 2000, $owner->id, 'account', $foreign->id);
            $this->fail('Expected the foreign account to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('paymentMethod', $e->errors());
        }

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(10, Product::withoutGlobalScopes()->find($product->id)->stock_quantity);
        $this->assertSame(WhatsAppOrderStatus::Pending, WhatsAppOrder::withoutGlobalScopes()->find($order->id)->status);

        $sale = AcceptWhatsAppOrder::handle($order, $lines, 0, null, 2000, $owner->id, 'account', $own->id);
        $payment = SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->sole();
        $this->assertSame($own->id, $payment->shop_account_id);
        $this->assertSame($shop->id, $payment->shop_id);
    }

    // ── Settings delete guard and isolation ───────────────────────────

    public function test_a_shop_account_with_sale_payments_cannot_be_deleted(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        $used = $this->account($shop);
        $unused = $this->account($shop, 'Spare JazzCash');
        $this->actingAs($owner);

        CreateSale::handle([$this->line($this->product($shop))], 0, null, $owner->id, now()->toDateString(), 1000, paymentMethod: 'account', shopAccountId: $used->id);

        Livewire::test('settings.index')->call('deleteShopAccount', $used->id);
        $this->assertNotNull(ShopAccount::find($used->id));

        Livewire::test('settings.index')->call('deleteShopAccount', $unused->id);
        $this->assertNull(ShopAccount::find($unused->id));
    }

    public function test_the_picker_and_paid_by_line_only_show_the_viewers_own_shop(): void
    {
        [$shop, $owner] = $this->shopWithOwner();
        [$otherShop, $otherOwner] = $this->shopWithOwner('Shop B');
        $this->account($shop, 'Meezan Bank');
        $this->account($otherShop, 'Secret Other Bank');
        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->assertSee('Meezan Bank · Bank')
            ->assertDontSee('Secret Other Bank');

        $sale = CreateSale::handle([$this->line($this->product($shop))], 0, null, $owner->id, now()->toDateString(), 1000, paymentMethod: 'cash');

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Paid by:')
            ->assertSee('Cash');

        $this->actingAs($otherOwner);
        $this->get(route('sales.show', $sale))->assertNotFound();
        Livewire::test('sales.create')->assertDontSee('Meezan Bank');
    }
}
