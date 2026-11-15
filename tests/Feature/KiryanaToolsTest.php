<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\CustomerPayments;
use App\Enums\ConnectionStatus;
use App\Enums\PaymentGateway;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerPayment;
use App\Models\PaymentGatewayConnection;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Payments\JazzCash;
use App\Services\BusinessCategories;
use App\Services\WhatsApp\CustomerNotifications;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class KiryanaToolsTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Madina Store', 'business_category' => 'kiryana', 'phone' => '0300 1112223']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id]);
        $this->actingAs($this->owner);
    }

    protected function cooking(array $extra = []): Product
    {
        return Product::create($extra + ['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Cooking Oil 1L', 'price' => 500, 'cost_price' => 420, 'stock_quantity' => 0, 'details' => ['category' => null],
            'pack_name' => 'Carton', 'pack_size' => 12, 'pack_price' => 5700]);
    }

    // ── 1. Expiry defaults ──────────────────────────────────────────────

    public function test_kiryana_and_pharmacy_start_with_expiry_tracking_and_others_do_not(): void
    {
        foreach (['kiryana' => true, 'pharmacy' => true, 'clothing' => false, 'social_seller' => false, 'mobile_electronics' => false] as $category => $expected) {
            $shop = Shop::create(['name' => ucfirst($category), 'business_category' => $category]);
            app(BusinessCategories::class)->applyDefaults($shop);
            $this->assertSame($expected, $shop->fresh()->expiry_tracking_enabled, $category);
        }

        // Defaults only ever switch it on: a shop that turned it off keeps it off until defaults are applied again.
        $kiryana = Shop::create(['name' => 'K', 'business_category' => 'kiryana', 'expiry_tracking_enabled' => true]);
        $kiryana->update(['expiry_tracking_enabled' => false]);
        $clothing = Shop::create(['name' => 'C', 'business_category' => 'clothing', 'expiry_tracking_enabled' => true]);
        app(BusinessCategories::class)->applyDefaults($clothing);
        $this->assertTrue($clothing->fresh()->expiry_tracking_enabled);
    }

    // ── 5. Packs ────────────────────────────────────────────────────────

    public function test_a_product_gets_a_pack_from_the_form_and_dozen_fills_twelve(): void
    {
        \App\Models\MainCategory::ensureDefaultsExist($this->shop->id);
        \App\Models\MainCategory::create(['shop_id' => $this->shop->id, 'name' => 'Grocery', 'slug' => 'grocery', 'is_builtin' => false]);
        $product = Product::create(['shop_id' => $this->shop->id, 'type' => 'grocery', 'name' => 'Eggs', 'price' => 25, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        Livewire::test('products.index')->call('openEdit', $product->id)->assertSee('Bought or sold by the carton?')
            ->set('pack_name', 'Dozen')->assertSet('pack_size', '12')->set('pack_price', '290')->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertTrue($product->hasPack());
        $this->assertSame('1 dozen', $product->packLabel());
        $this->assertSame(290.0, $product->packSellingPrice());

        Livewire::test('products.index')->call('openEdit', $product->id)->set('pack_name', '')->call('save')->assertHasNoErrors();
        $this->assertFalse($product->fresh()->hasPack());

        Livewire::test('products.index')->call('openEdit', $product->id)->set('pack_name', 'Carton')->set('pack_size', '1')->call('save')->assertHasErrors(['pack_size']);
    }

    public function test_stock_in_by_the_carton_adds_pieces_and_owes_the_exact_carton_total(): void
    {
        $product = $this->cooking();
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Al-Rehman Traders']);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)->assertSet('buyIn', 'pack')->assertSet('costPrice', '5040')
            ->set('quantity', '3')->set('costPrice', '5000')->set('sellingPrice', '500')
            ->call('addLine')->assertHasNoErrors()->assertSee('3 cartons of 12 = 36 pieces')
            ->set('vendorId', (string) $vendor->id)->set('paymentStatus', 'unpaid')
            ->call('save')->assertHasNoErrors();

        $entry = StockIn::sole();
        $this->assertEquals(36, $product->fresh()->stock_quantity);
        $this->assertEquals(36, $entry->quantity);
        $this->assertEquals(416.67, (float) $entry->cost_price);
        $this->assertEquals(15000, (float) $entry->total_cost);
        $this->assertSame([3, 12, 'Carton'], [$entry->packs, $entry->pack_size, $entry->pack_name]);
        $this->assertEquals(15000, $vendor->fresh()->totalOwed());

        $this->get(route('stock-ins.history'))->assertSee('3 × carton of 12');
    }

    public function test_the_same_product_can_still_be_stocked_loose(): void
    {
        $product = $this->cooking();

        Livewire::test('stock-ins.create')->set('productId', (string) $product->id)->set('buyIn', 'piece')->assertSet('costPrice', '420')
            ->set('quantity', '5')->set('sellingPrice', '500')->call('addLine')->call('save')->assertHasNoErrors();

        $this->assertEquals(5, $product->fresh()->stock_quantity);
        $this->assertNull(StockIn::sole()->packs);
    }

    public function test_the_register_sells_a_whole_carton_at_its_own_price(): void
    {
        $product = $this->cooking(['stock_quantity' => 30]);

        Livewire::test('sales.create')->call('addToCart', $product->id)->assertSee('Carton of 12')
            ->call('sellAs', $product->id, 'pack')->assertSet("cart.{$product->id}.quantity", 1)->assertSet("cart.{$product->id}.max_stock", 2)
            ->call('addToCart', $product->id)->assertSet("cart.{$product->id}.quantity", 2)
            ->call('addToCart', $product->id)->assertSet("cart.{$product->id}.quantity", 2)
            ->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertEquals(11400, (float) $sale->total);
        $this->assertEquals(24, $sale->items->sum('quantity'));
        $this->assertEquals(6, $product->fresh()->stock_quantity);
    }

    public function test_a_carton_without_its_own_price_sells_as_that_many_pieces(): void
    {
        $product = $this->cooking(['stock_quantity' => 30, 'pack_price' => null]);

        Livewire::test('sales.create')->call('addToCart', $product->id)->call('sellAs', $product->id, 'pack')
            ->call('sellAs', $product->id, 'piece')->assertSet("cart.{$product->id}.quantity", 12)
            ->call('sellAs', $product->id, 'pack')->call('completeSale')->assertHasNoErrors();

        $this->assertEquals(6000, (float) Sale::sole()->total);
    }

    // ── 3. Ledger link ──────────────────────────────────────────────────

    protected function customerWithDues(): Customer
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal Ahmed', 'phone' => '0321 5550000']);
        UdhaarTransaction::create(['shop_id' => $this->shop->id, 'customer_id' => $customer->id, 'type' => 'given', 'amount' => 2000, 'transaction_date' => now()->subDays(10)->toDateString()]);
        $product = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Basmati Rice 5kg', 'price' => 3000, 'stock_quantity' => 5, 'details' => ['category' => null]]);
        CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]], 0, $customer->id, $this->owner->id, now()->subDays(3)->toDateString(), 1000);

        return $customer;
    }

    public function test_the_customer_sees_their_own_balance_and_history_at_a_private_link(): void
    {
        $customer = $this->customerWithDues();
        $url = $customer->ledgerUrl();
        auth()->logout();

        $this->get($url)->assertOk()->assertHeader('X-Frame-Options')
            ->assertSee('Bilal Ahmed’s account', false)->assertSee('Rs 4,000.00')
            ->assertSee('Udhaar given')->assertSee('Bought on credit')->assertSee('Basmati Rice 5kg')
            ->assertDontSee('Pay with JazzCash');

        $this->get('/k/'.str_repeat('x', 32))->assertNotFound();
        $this->get('/k/short')->assertNotFound();
    }

    public function test_a_new_link_shuts_the_old_one(): void
    {
        $customer = $this->customerWithDues();
        $old = $customer->ledgerUrl();

        Livewire::test('customers.share-and-remind', ['customer' => $customer])->assertSee($old)->call('newLink');

        $this->get($old)->assertNotFound();
        $this->get($customer->fresh()->ledgerUrl())->assertOk();
    }

    public function test_the_udhaar_page_offers_the_link_and_no_sms_while_it_is_off(): void
    {
        $customer = $this->customerWithDues();

        $this->get(route('udhaar.show', $customer))->assertSee($customer->fresh()->ledgerUrl())->assertSee('Share on WhatsApp')->assertDontSee('Send by SMS');
    }

    // ── 2. Paying from the link (JazzCash) ──────────────────────────────

    protected function jazzcash(): PaymentGatewayConnection
    {
        $account = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'JazzCash Business', 'provider_type' => 'JazzCash']);

        return PaymentGatewayConnection::create(['shop_id' => $this->shop->id, 'gateway' => PaymentGateway::JazzCash, 'shop_account_id' => $account->id, 'status' => ConnectionStatus::Connected,
            'credentials' => ['merchant_id' => 'MC12345', 'password' => 'pass-123', 'integrity_salt' => 'salt-xyz', 'environment' => 'sandbox']]);
    }

    /** What JazzCash posts back, signed with the shop's salt. */
    protected function jazzcashResult(CustomerPayment $payment, string $code = '000', ?int $paisa = null, string $salt = 'salt-xyz'): array
    {
        $fields = ['pp_Amount' => (string) ($paisa ?? JazzCash::paisa((float) $payment->amount)), 'pp_BillReference' => 'C'.$payment->customer_id, 'pp_Language' => 'EN', 'pp_MerchantID' => 'MC12345',
            'pp_ResponseCode' => $code, 'pp_ResponseMessage' => $code === '000' ? 'Thank you for Using JazzCash' : 'Transaction failed', 'pp_RetreivalReferenceNo' => '260116123456',
            'pp_TxnCurrency' => 'PKR', 'pp_TxnDateTime' => '20261116120000', 'pp_TxnRefNo' => $payment->reference, 'pp_TxnType' => 'MWALLET', 'pp_Version' => '1.1'];

        return $fields + ['pp_SecureHash' => JazzCash::sign($fields, $salt)];
    }

    public function test_paying_from_the_link_sends_a_signed_form_to_jazzcash(): void
    {
        $customer = $this->customerWithDues();
        $this->jazzcash();
        $url = $customer->ledgerUrl();
        auth()->logout();

        $this->get($url)->assertSee('Pay with JazzCash');

        $response = $this->post($url.'/pay', ['amount' => 2500])->assertOk()->assertSee(JazzCash::SANDBOX, false);
        $payment = CustomerPayment::sole();
        $this->assertSame(CustomerPayment::PENDING, $payment->status);
        $this->assertEquals(2500, (float) $payment->amount);
        $this->assertLessThanOrEqual(20, strlen($payment->reference));

        preg_match_all('/name="(pp[^"]+)" value="([^"]*)"/', $response->getContent(), $m);
        $fields = array_combine($m[1], array_map('html_entity_decode', $m[2]));
        $this->assertSame('250000', $fields['pp_Amount']);
        $this->assertSame(route('payments.jazzcash.return'), $fields['pp_ReturnURL']);
        $this->assertTrue(JazzCash::verify($fields, 'salt-xyz'));

        $this->post($url.'/pay', ['amount' => 9999])->assertRedirect($url)->assertSessionHasErrors('amount');
    }

    public function test_a_signed_paid_result_pays_udhaar_first_then_the_oldest_sale_once_only(): void
    {
        $customer = $this->customerWithDues();
        $connection = $this->jazzcash();
        $link = $customer->ledgerUrl();
        $payment = CustomerPayments::start($customer, $connection, 2500);
        auth()->logout();

        $this->post(route('payments.jazzcash.return'), $this->jazzcashResult($payment))->assertRedirect($link)->assertSessionHas('paid');
        $this->post(route('payments.jazzcash.return'), $this->jazzcashResult($payment)); // JazzCash's IPN repeats it

        $payment->refresh();
        $this->assertSame(CustomerPayment::PAID, $payment->status);
        $this->assertEquals([['source' => 'udhaar', 'id' => null, 'amount' => 2000], ['source' => 'sale', 'id' => Sale::sole()->id, 'amount' => 500]], $payment->applied_to);
        $this->assertSame(1500.0, CustomerDuesStatement::for($customer->fresh())->total());
        $this->assertSame(1, UdhaarTransaction::where('type', 'repayment')->count());
        $this->assertSame($connection->shop_account_id, UdhaarTransaction::where('type', 'repayment')->sole()->shop_account_id);

        $this->get($customer->ledgerUrl())->assertSee('Rs 1,500.00');
    }

    public function test_a_forged_or_wrong_amount_result_changes_nothing(): void
    {
        $customer = $this->customerWithDues();
        $customer->ledgerUrl();
        $payment = CustomerPayments::start($customer, $this->jazzcash(), 2500);
        auth()->logout();

        $this->post(route('payments.jazzcash.return'), $this->jazzcashResult($payment, salt: 'guessed'))->assertStatus(400);
        $this->post(route('payments.jazzcash.return'), $this->jazzcashResult($payment, paisa: 100))->assertRedirect()->assertSessionHas('failed');
        $this->assertSame(CustomerPayment::FAILED, $payment->fresh()->status);
        $this->assertSame(4000.0, CustomerDuesStatement::for($customer->fresh())->total());

        $declined = CustomerPayments::start($customer, $payment->connection, 500);
        $this->post(route('payments.jazzcash.return'), $this->jazzcashResult($declined, '124'));
        $this->assertSame(CustomerPayment::PENDING, $declined->fresh()->status);
    }

    // ── 2 + 4. Reminders: the link inside, and SMS ──────────────────────

    public function test_the_reminder_carries_the_link_and_says_pay_when_jazzcash_is_on(): void
    {
        $customer = $this->customerWithDues();
        $preview = app(CustomerNotifications::class)->paymentReminderPreview($customer);
        $this->assertStringContainsString('See your account here: '.$customer->fresh()->ledgerUrl(), $preview['text']);

        $this->jazzcash();
        $this->assertStringContainsString('See your account and pay here: ', app(CustomerNotifications::class)->paymentReminderPreview($customer)['text']);
    }

    public function test_sms_reminders_stay_off_until_switched_on_then_send_one_short_sms_within_the_cap(): void
    {
        $customer = $this->customerWithDues();
        $notifications = app(CustomerNotifications::class);
        $this->assertFalse($notifications->smsRemindersAvailable());

        try {
            $notifications->paymentReminderSms($customer, $this->owner->id);
            $this->fail('SMS must be refused while off.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('switched on', $e->getMessage());
        }

        config(['reminders.sms.enabled' => true, 'reminders.sms.monthly_cap_per_shop' => 1, 'verification.plivo.auth_id' => 'MA123', 'verification.plivo.auth_token' => 'tok', 'verification.plivo.sender' => 'HISABKITAB']);
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response(['message_uuid' => ['uuid-1'], 'api_id' => 'x'], 202);
        });

        Livewire::test('customers.share-and-remind', ['customer' => $customer])->assertSee('0 of 1 SMS reminders used this month')->call('remind', 'sms');

        $notification = CustomerNotification::sole();
        $this->assertSame(['sms', 'sent'], [$notification->delivery_mode, $notification->status->value]);
        $this->assertSame('+923215550000', $sent[0]['dst']);
        $this->assertStringContainsString($customer->fresh()->ledgerUrl(), $sent[0]['text']);
        $this->assertLessThanOrEqual(160, strlen($sent[0]['text']));
        $this->assertStringStartsWith('Madina Store: Bilal Ahmed, Rs 4,000 is due.', $sent[0]['text']);

        // The cap holds even after the cooldown passes.
        $this->travel(8)->days();
        $this->expectExceptionMessage('used up');
        $notifications->paymentReminderSms($customer, $this->owner->id);
    }
}
