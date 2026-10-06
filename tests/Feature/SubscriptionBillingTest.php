<?php

namespace Tests\Feature;

use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
    }

    protected function shop(array $attributes = []): Shop
    {
        return Shop::create(array_merge(['name' => 'Zain Mobile Shop', 'plan_type' => 'monthly'], $attributes));
    }

    protected function record(Shop $shop, array $overrides = []): SubscriptionInvoice
    {
        return app(SubscriptionBillingService::class)->create($shop, array_merge([
            'period_start' => '2026-10-01',
            'months' => 1,
            'amount' => 1000,
            'discount' => 0,
            'notes' => null,
        ], $overrides), null);
    }

    public function test_the_first_invoice_number_uses_the_branding_prefix_and_the_current_year(): void
    {
        $invoice = $this->record($this->shop());

        $this->assertSame('HOK-'.now()->format('Y').'-0001', $invoice->invoice_number);
    }

    public function test_two_sequential_creates_get_distinct_consecutive_numbers(): void
    {
        $shop = $this->shop();
        $first = $this->record($shop);
        $second = $this->record($shop);

        $this->assertSame('HOK-'.now()->format('Y').'-0001', $first->invoice_number);
        $this->assertSame('HOK-'.now()->format('Y').'-0002', $second->invoice_number);
    }

    public function test_a_cancelled_invoice_keeps_its_number_and_the_next_one_never_reuses_it(): void
    {
        $shop = $this->shop();
        $first = $this->record($shop);
        app(SubscriptionBillingService::class)->cancel($first, 'Entered by mistake');

        $next = $this->record($shop);

        $this->assertSame('HOK-'.now()->format('Y').'-0002', $next->invoice_number);
        $this->assertDatabaseHas('subscription_invoices', ['invoice_number' => $first->invoice_number, 'status' => 'cancelled']);
    }

    public function test_the_invoice_number_is_unique_in_the_database(): void
    {
        $shop = $this->shop();
        $invoice = $this->record($shop);

        $this->expectException(\Illuminate\Database\QueryException::class);
        SubscriptionInvoice::create([
            'shop_id' => $shop->id, 'invoice_number' => $invoice->invoice_number, 'plan_type' => 'monthly',
            'period_start' => '2026-11-01', 'months' => 1, 'period_end' => '2026-11-30',
            'amount' => 1, 'discount' => 0, 'total' => 1, 'status' => 'unpaid',
        ]);
    }

    public function test_money_is_derived_in_one_place_as_amount_minus_discount(): void
    {
        $this->assertSame(900.0, SubscriptionInvoice::totalFor(1000, 100));
        $this->assertSame(0.0, SubscriptionInvoice::totalFor(100, 100));
        $this->assertSame(0.0, SubscriptionInvoice::totalFor(100, 250));
    }

    public function test_the_stored_total_matches_amount_minus_discount(): void
    {
        $invoice = $this->record($this->shop(), ['amount' => 1500.50, 'discount' => 200.25]);

        $this->assertSame('1300.25', (string) $invoice->total);
        $this->assertSame('1500.50', (string) $invoice->amount);
        $this->assertSame('200.25', (string) $invoice->discount);
    }

    public function test_the_period_end_is_the_last_day_covered_for_a_single_month(): void
    {
        $invoice = $this->record($this->shop(), ['period_start' => '2026-01-01', 'months' => 1]);

        $this->assertSame('2026-01-31', $invoice->period_end->toDateString());
    }

    public function test_the_period_end_spans_the_requested_number_of_months(): void
    {
        $invoice = $this->record($this->shop(), ['period_start' => '2026-03-15', 'months' => 12]);

        $this->assertSame('2027-03-14', $invoice->period_end->toDateString());
    }

    public function test_the_period_end_is_clamped_to_month_length_without_overflowing(): void
    {
        $end = SubscriptionInvoice::periodEndFor(Carbon::parse('2026-01-31'), 1);

        $this->assertSame('2026-02-27', $end->toDateString());
    }

    public function test_the_plan_type_is_snapshotted_on_the_invoice(): void
    {
        $shop = $this->shop(['plan_type' => 'yearly']);
        $invoice = $this->record($shop);

        $shop->update(['plan_type' => 'monthly']);

        $this->assertSame('yearly', $invoice->fresh()->plan_type);
        $this->assertSame('Yearly', $invoice->fresh()->planLabel());
    }

    public function test_marking_paid_sets_the_status_and_paid_date_and_unpaid_clears_them(): void
    {
        $service = app(SubscriptionBillingService::class);
        $invoice = $this->record($this->shop());

        $paid = $service->markPaid($invoice, '2026-10-05');
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $paid->status);
        $this->assertSame('2026-10-05', $paid->paid_at->toDateString());

        $unpaid = $service->markUnpaid($paid);
        $this->assertSame(SubscriptionInvoiceStatus::Unpaid, $unpaid->status);
        $this->assertNull($unpaid->paid_at);
    }

    public function test_a_record_can_be_created_already_paid(): void
    {
        $shop = $this->shop();

        $invoice = app(SubscriptionBillingService::class)->create($shop, [
            'period_start' => '2026-10-01', 'months' => 1, 'amount' => 500, 'discount' => 0,
        ], null, '2026-10-02');

        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->status);
        $this->assertSame('2026-10-02', $invoice->paid_at->toDateString());
    }

    public function test_editing_recomputes_the_total_and_period_end(): void
    {
        $service = app(SubscriptionBillingService::class);
        $invoice = $this->record($this->shop(), ['amount' => 1000, 'months' => 1]);

        $edited = $service->update($invoice, [
            'period_start' => '2026-10-01', 'months' => 3, 'amount' => 2000, 'discount' => 500, 'notes' => 'Revised',
        ]);

        $this->assertSame('1500.00', (string) $edited->total);
        $this->assertSame('2026-12-31', $edited->period_end->toDateString());
        $this->assertSame('Revised', $edited->notes);
    }

    public function test_a_cancelled_invoice_cannot_be_edited(): void
    {
        $service = app(SubscriptionBillingService::class);
        $invoice = $this->record($this->shop());
        $service->cancel($invoice, 'Duplicate');
        $cancelled = $invoice->fresh();

        $this->expectException(\RuntimeException::class);
        $service->update($cancelled, ['period_start' => '2026-10-01', 'months' => 1, 'amount' => 1]);
    }

    public function test_a_cancelled_invoice_cannot_change_status(): void
    {
        $service = app(SubscriptionBillingService::class);
        $invoice = $service->cancel($this->record($this->shop()), 'Duplicate');

        $this->expectException(\RuntimeException::class);
        $service->markPaid($invoice);
    }

    public function test_cancelling_keeps_the_row_and_records_the_reason_and_time(): void
    {
        $invoice = app(SubscriptionBillingService::class)->cancel($this->record($this->shop()), 'Entered for the wrong shop');

        $this->assertSame(SubscriptionInvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame('Entered for the wrong shop', $invoice->cancel_reason);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertDatabaseCount('subscription_invoices', 1);
    }

    public function test_a_cancelled_invoice_cannot_be_cancelled_twice(): void
    {
        $service = app(SubscriptionBillingService::class);
        $invoice = $service->cancel($this->record($this->shop()), 'First');

        $this->expectException(\RuntimeException::class);
        $service->cancel($invoice, 'Second');
    }

    public function test_the_shop_summary_bills_paid_and_outstanding_and_excludes_cancelled(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $service = app(SubscriptionBillingService::class);

        $paid = $service->create($shop, ['period_start' => '2026-01-01', 'months' => 1, 'amount' => 1000, 'discount' => 0], null, '2026-01-02');
        $unpaid = $this->record($shop, ['amount' => 500]);
        $cancelled = $this->record($shop, ['amount' => 9000]);
        $service->cancel($cancelled, 'Mistake');

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->assertViewHas('totalBilled', 1500.0)
            ->assertViewHas('totalPaid', 1000.0)
            ->assertViewHas('outstanding', 500.0);
    }

    public function test_the_summary_is_isolated_between_shops(): void
    {
        $this->actingAs($this->superAdmin());
        $shopA = $this->shop(['name' => 'A']);
        $shopB = $this->shop(['name' => 'B']);
        $this->record($shopA, ['amount' => 100]);
        $this->record($shopB, ['amount' => 9999]);

        Livewire::test('admin.shops.billing', ['shopId' => $shopA->id])
            ->assertViewHas('totalBilled', 100.0);
    }
}
