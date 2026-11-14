<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\ReceiveGoods;
use App\Actions\RecordSalePayment;
use App\Actions\SavePurchaseOrder;
use App\Actions\UpdateSale;
use App\Enums\WarrantyType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WhatsApp\CustomerNotifications;
use App\Support\CustomerDuesStatement;
use App\Support\InstallmentSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WarrantyAndInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');

        $this->shop = Shop::create(['name' => 'Ali Mobiles', 'business_category' => 'mobile_electronics']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id]);
        $this->customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal', 'phone' => '+923001234567']);
        $this->actingAs($this->owner);
    }

    protected function brand(Shop $shop): void
    {
        \App\Models\Brand::firstOrCreate(['shop_id' => $shop->id, 'name' => 'Samsung']);
    }

    protected function phone(string $imei = '356789104523117', ?int $months = 12, string $type = 'brand', float $price = 40000, ?Shop $shop = null): Product
    {
        $this->brand($shop ?? $this->shop);

        return Product::create([
            'shop_id' => ($shop ?? $this->shop)->id, 'type' => 'mobile', 'name' => 'Galaxy A15 '.substr($imei, -4), 'price' => $price, 'stock_quantity' => 1,
            'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => $imei, 'category' => null],
            'warranty_months' => $months, 'warranty_type' => $months ? $type : null,
        ]);
    }

    protected function sell(Product $product, float $paid = -1, ?int $customerId = null, array $installments = []): Sale
    {
        return CreateSale::handle(
            [['product_id' => $product->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]],
            0, $customerId, $this->owner->id, now()->toDateString(), $paid < 0 ? (float) $product->price : $paid,
            installments: $installments,
        );
    }

    // ── Warranty ────────────────────────────────────────────────────────

    public function test_the_product_form_saves_a_warranty_for_device_shops_only(): void
    {
        $product = $this->phone(months: null);

        Livewire::test('products.index')->call('openEdit', $product->id)->assertSee('Warranty (months)')
            ->set('warranty_months', '6')->set('warranty_type', 'shop')->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertSame(6, $product->warranty_months);
        $this->assertSame(WarrantyType::Shop, $product->warranty_type);

        Livewire::test('products.index')->call('openEdit', $product->id)->set('warranty_months', '')->call('save')->assertHasNoErrors();
        $this->assertNull($product->fresh()->warranty_months);
        $this->assertNull($product->fresh()->warranty_type);

        $this->shop->update(['business_category' => 'kiryana']);
        $this->owner->unsetRelation('shop');
        Livewire::test('products.index')->call('openEdit', $product->id)->assertDontSee('Warranty (months)');
    }

    public function test_a_sale_freezes_the_imei_and_warranty_and_edits_keep_them(): void
    {
        $product = $this->phone();
        $sale = $this->sell($product, 0, $this->customer->id);
        $item = $sale->items()->sole();

        $this->assertSame('356789104523117', $item->serial_number);
        $this->assertSame(12, $item->warranty_months);
        $this->assertSame(WarrantyType::Brand, $item->warranty_type);
        $this->assertSame('2027-11-14', $item->warrantyEndsOn($sale->created_at)->toDateString());

        $product->update(['warranty_months' => 3, 'details' => ['brand' => 'Samsung', 'imei' => 'CHANGED']]);
        UpdateSale::handle($sale, [['product_id' => $product->id, 'product_name' => $item->product_name, 'product_type' => 'mobile', 'quantity' => 1, 'discount' => 500, 'custom_price' => null]], 0, $this->customer->id, $this->owner->id, now()->toDateString());

        $item = $sale->fresh()->items()->sole();
        $this->assertSame('356789104523117', $item->serial_number);
        $this->assertSame(12, $item->warranty_months);
    }

    public function test_the_lookup_finds_a_unit_by_full_imei_last_digits_or_invoice_number(): void
    {
        $sale = $this->sell($this->phone(), customerId: $this->customer->id);

        foreach (['356789104523117', '4523117', $sale->invoiceNumber(), (string) $sale->id] as $term) {
            Livewire::test('sales.warranty')->set('search', $term)
                ->assertSee('Under warranty')->assertSee('365 days left')->assertSee('14 Nov 2027')
                ->assertSee($sale->invoiceNumber())->assertSee('Bilal')->assertSee('12 months')->assertSee('Brand warranty');
        }

        Livewire::test('sales.warranty')->set('search', 'A1')->assertSee('Keep typing');
        Livewire::test('sales.warranty')->set('search', '117')->assertSee('Nothing sold matches'); // a short number is an invoice number
        Livewire::test('sales.warranty')->set('search', '999999999')->assertSee('Nothing sold matches');
    }

    public function test_the_lookup_shows_expired_cancelled_unwarranted_and_unsold_units(): void
    {
        $expired = $this->sell($this->phone('111111111111111', months: 1));
        $none = $this->sell($this->phone('222222222222222', months: null));
        $cancelled = $this->sell($this->phone('333333333333333'));
        CancelSale::handle($cancelled, $this->owner->id, 'Returned');
        $this->phone('444444444444444');

        $this->travel(40)->days();

        Livewire::test('sales.warranty')->set('search', '111111111111111')->assertSee('Expired')->assertSee('Ended 14 Dec 2026');
        Livewire::test('sales.warranty')->set('search', '222222222222222')->assertSee('No warranty');
        Livewire::test('sales.warranty')->set('search', '333333333333333')->assertSee('Sale cancelled');
        Livewire::test('sales.warranty')->set('search', '444444444444444')->assertSee('In stock, not sold');
    }

    public function test_a_sale_from_before_warranty_tracking_is_found_by_the_product_imei(): void
    {
        $sale = $this->sell($this->phone());
        $sale->items()->update(['serial_number' => null, 'warranty_months' => null, 'warranty_type' => null]);

        Livewire::test('sales.warranty')->set('search', '4523117')->assertSee($sale->invoiceNumber())->assertSee('No warranty');
    }

    public function test_the_lookup_never_shows_another_shops_sales(): void
    {
        $other = Shop::create(['name' => 'Other', 'business_category' => 'mobile_electronics']);
        $this->actingAs(User::factory()->create(['shop_id' => $other->id]));
        $this->sell($this->phone('555555555555555', shop: $other));
        $this->actingAs($this->owner);

        Livewire::test('sales.warranty')->set('search', '555555555555555')->assertSee('Nothing sold matches');
    }

    public function test_the_receipt_and_invoice_show_the_warranty(): void
    {
        $sale = $this->sell($this->phone());

        $this->get(route('sales.show', $sale))->assertSee('IMEI 356789104523117')->assertSee('12-month brand warranty, until 14 Nov 2027');
        $this->assertStringContainsString('12-month brand warranty, until 14 Nov 2027', view('pdf.invoice', ['sale' => $sale->fresh()])->render());
    }

    // ── Instalments ─────────────────────────────────────────────────────

    public function test_an_even_plan_puts_the_rounding_on_the_last_instalment(): void
    {
        $plan = InstallmentSchedule::plan(10000, 3, Carbon::parse('2027-01-31'));

        $this->assertSame([3333.0, 3333.0, 3334.0], array_column($plan, 'amount'));
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], array_column($plan, 'due_date'));
    }

    public function test_the_register_sells_on_instalments_with_a_down_payment(): void
    {
        $product = $this->phone(price: 40000);

        Livewire::test('sales.create')->assertSee('Instalments')
            ->call('addToCart', $product->id)
            ->set('onInstallments', true)->set('amountPaidNow', '10000')->set('installmentCount', '3')->set('firstDueDate', '2026-12-15')
            ->assertSee('15 Feb 2027')
            ->call('completeSale')->assertHasErrors(['customerId'])
            ->set('customerId', (string) $this->customer->id)
            ->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertEquals(10000, $sale->amountPaid());
        $this->assertEquals(30000, $sale->amountDue());
        $this->assertSame(['2026-12-15', '2027-01-15', '2027-02-15'], $sale->installments->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertSame([10000.0, 10000.0, 10000.0], $sale->installments->map(fn ($i) => (float) $i->amount)->all());

        $this->get(route('sales.show', $sale))->assertSee('Instalment Plan')->assertSee('1 of 3 · 15 Dec 2026');
    }

    public function test_the_register_refuses_a_plan_with_nothing_left_to_pay_or_a_date_before_the_sale(): void
    {
        $product = $this->phone(price: 40000);
        $register = fn () => Livewire::test('sales.create')->call('addToCart', $product->id)->set('customerId', (string) $this->customer->id)->set('onInstallments', true);

        $register()->set('amountPaidNow', '40000')->call('completeSale')->assertHasErrors(['amountPaidNow']);
        $register()->set('firstDueDate', '2026-11-01')->call('completeSale')->assertHasErrors(['firstDueDate']);
        $register()->set('installmentCount', '0')->call('completeSale')->assertHasErrors(['installmentCount']);

        $this->assertSame(0, Sale::count());
    }

    public function test_the_action_refuses_a_plan_that_does_not_add_up(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell($this->phone(price: 40000), 10000, $this->customer->id, [['sequence' => 1, 'due_date' => '2026-12-15', 'amount' => 20000]]);
    }

    public function test_shops_that_do_not_sell_devices_get_no_instalments_or_warranty_screens(): void
    {
        $this->shop->update(['business_category' => 'kiryana']);
        $this->owner->unsetRelation('shop');
        $product = $this->phone(price: 1000);

        Livewire::test('sales.create')->assertDontSee('data-installment-panel', false)
            ->call('addToCart', $product->id)->set('onInstallments', true)->call('completeSale')->assertHasNoErrors();

        $this->assertSame(0, Sale::sole()->installments()->count());
        $this->assertEquals(0, Sale::sole()->amountDue());
        $this->get(route('sales.warranty'))->assertNotFound();
        $this->get(route('sales.installments'))->assertNotFound();
        $this->get(route('sales.history'))->assertDontSee('Warranty Check')->assertDontSee(route('sales.installments'));
    }

    protected function planSale(): Sale
    {
        return $this->sell($this->phone(price: 40000), 10000, $this->customer->id, InstallmentSchedule::plan(30000, 3, Carbon::parse('2026-12-15')));
    }

    public function test_payments_pay_the_earliest_instalment_first(): void
    {
        $sale = $this->planSale();
        RecordSalePayment::handle($sale, 15000, $this->owner->id);

        $rows = InstallmentSchedule::for($sale->fresh())->rows();
        $this->assertSame(['paid', 'upcoming', 'upcoming'], $rows->pluck('status')->all());
        $this->assertSame([0.0, 5000.0, 10000.0], $rows->pluck('left')->all());
        $this->assertSame('Next Rs 5,000 on 15 Jan 2027', InstallmentSchedule::for($sale->fresh())->summary());
    }

    public function test_a_late_instalment_shows_on_the_udhaar_page_and_list_without_changing_what_is_owed(): void
    {
        $sale = $this->planSale();
        $this->travelTo('2026-12-20');

        $schedule = InstallmentSchedule::for($sale->fresh());
        $this->assertSame(10000.0, $schedule->overdueAmount());
        $this->assertSame('Rs 10,000 overdue since 15 Dec · next Rs 10,000 on 15 Jan 2027', $schedule->summary());

        // Still owed in full everywhere balances are added up.
        $this->assertSame(30000.0, CustomerDuesStatement::for($this->customer)->total());

        $this->get(route('udhaar.show', $this->customer))->assertSee('Instalments')->assertSee('Rs 10,000 overdue since 15 Dec');
        $this->get(route('sales.installments'))->assertSee($sale->invoiceNumber())->assertSee('Rs 10,000 overdue since 15 Dec');
        Livewire::test('sales.installments')->set('show', 'paid')->assertDontSee($sale->invoiceNumber());
    }

    public function test_the_reminder_asks_only_for_what_has_fallen_due(): void
    {
        $this->planSale();
        $notifications = app(CustomerNotifications::class);

        $before = $notifications->paymentReminderPreview($this->customer);
        $this->assertSame(0.0, (float) $before['total']);
        $this->assertStringContainsString('Next instalment for', $before['text']);
        $this->assertStringContainsString('Rs 10,000 on 15 Dec 2026', $before['text']);

        try {
            $notifications->paymentReminder($this->customer, $this->owner->id);
            $this->fail('A reminder with nothing due should be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('next instalment hasn\'t fallen due', $e->getMessage());
        }

        $this->travelTo('2027-01-16');
        $after = $notifications->paymentReminderPreview($this->customer);
        $this->assertSame(20000.0, (float) $after['total']);
        $this->assertStringContainsString('Rs 20,000 due now (Rs 30,000 left in total)', $after['text']);
    }

    public function test_the_invoice_prints_the_plan(): void
    {
        $sale = $this->planSale();

        $html = view('pdf.invoice', ['sale' => $sale->fresh()])->render();
        $this->assertStringContainsString('Instalment plan', $html);
        $this->assertStringContainsString('3. 15 Feb 2027', $html);
    }
}
