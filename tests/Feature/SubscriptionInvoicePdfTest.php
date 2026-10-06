<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionInvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class SubscriptionInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function shop(array $attributes = []): Shop
    {
        return Shop::create(array_merge([
            'name' => 'Zain Mobile Shop',
            'phone' => '0300-1234567',
            'email' => 'zain@example.com',
            'address' => 'Shop 12, Main Bazaar, Lahore',
            'plan_type' => 'monthly',
        ], $attributes));
    }

    protected function record(Shop $shop, array $overrides = []): SubscriptionInvoice
    {
        return app(SubscriptionBillingService::class)->create($shop, array_merge([
            'period_start' => '2026-10-01', 'months' => 3, 'amount' => 1500, 'discount' => 200, 'notes' => 'Thanks for your business',
        ], $overrides), null);
    }

    protected function html(SubscriptionInvoice $invoice): string
    {
        return view('pdf.subscription-invoice', [
            'invoice' => $invoice->load('shop'),
            'shop' => $invoice->shop,
            'logo' => null,
            'paymentInstructions' => config('branding.billing_payment_instructions'),
            'supportContact' => config('branding.billing_support_contact'),
        ])->render();
    }

    protected function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_the_invoice_pdf_is_generated_as_a_real_pdf(): void
    {
        $invoice = $this->record($this->shop());

        $response = app(SubscriptionInvoicePdfService::class)->download($invoice);

        $this->assertStringStartsWith('%PDF', $this->capture($response));
    }

    public function test_the_invoice_shows_the_brand_number_shop_details_and_totals(): void
    {
        $invoice = $this->record($this->shop());
        $html = $this->html($invoice);

        $this->assertStringContainsString(config('branding.name'), $html);
        $this->assertStringContainsString(config('branding.domain'), $html);
        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('Zain Mobile Shop', $html);
        $this->assertStringContainsString('Shop 12, Main Bazaar, Lahore', $html);
        $this->assertStringContainsString('0300-1234567', $html);
        $this->assertStringContainsString('zain@example.com', $html);
        $this->assertStringContainsString('Rs 1,500.00', $html);
        $this->assertStringContainsString('Rs 200.00', $html);
        $this->assertStringContainsString('Rs 1,300.00', $html);
    }

    public function test_the_invoice_shows_the_period_and_plan(): void
    {
        $invoice = $this->record($this->shop(['plan_type' => 'yearly']));
        $html = $this->html($invoice);

        $this->assertStringContainsString('01 Oct 2026', $html);
        $this->assertStringContainsString('31 Dec 2026', $html);
        $this->assertStringContainsString('Yearly', $html);
    }

    public function test_an_unpaid_invoice_is_marked_unpaid_and_a_paid_one_paid(): void
    {
        $shop = $this->shop();
        $unpaid = $this->record($shop);
        $paid = app(SubscriptionBillingService::class)->markPaid($this->record($shop, ['notes' => null]), '2026-10-05');

        $this->assertStringContainsString('UNPAID', $this->html($unpaid));
        $this->assertStringContainsString('PAID', $this->html($paid));
        $this->assertStringNotContainsString('UNPAID', $this->html($paid));
    }

    public function test_a_cancelled_invoice_is_marked_cancelled_with_its_reason(): void
    {
        $invoice = app(SubscriptionBillingService::class)->cancel($this->record($this->shop()), 'Entered twice');
        $html = $this->html($invoice);

        $this->assertStringContainsString('CANCELLED', $html);
        $this->assertStringContainsString('Entered twice', $html);
    }

    public function test_payment_instructions_and_support_are_omitted_when_the_config_is_empty(): void
    {
        Config::set('branding.billing_payment_instructions', '');
        Config::set('branding.billing_support_contact', '');

        $html = $this->html($this->record($this->shop()));

        $this->assertStringNotContainsString('Payment Instructions', $html);
        $this->assertStringNotContainsString('Support', $html);
    }

    public function test_payment_instructions_and_support_appear_when_configured(): void
    {
        Config::set('branding.billing_payment_instructions', 'Bank: ABC · Account 0011223344');
        Config::set('branding.billing_support_contact', 'support@hisabokitab.com');

        $html = $this->html($this->record($this->shop()));

        $this->assertStringContainsString('Bank: ABC · Account 0011223344', $html);
        $this->assertStringContainsString('support@hisabokitab.com', $html);
    }

    public function test_a_long_shop_name_and_address_are_set_to_wrap(): void
    {
        $longName = str_repeat('Extraordinarily Long Mobile And Accessories Trading Company ', 3);
        $invoice = $this->record($this->shop([
            'name' => $longName,
            'address' => str_repeat('Plot 99 Industrial Area Block ', 6),
        ]));

        $html = $this->html($invoice);

        $this->assertStringContainsString($longName, $html);
        $this->assertStringContainsString('word-wrap: break-word', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
    }

    public function test_the_logo_is_embedded_as_a_local_raster_not_a_remote_or_svg_file(): void
    {
        $invoice = $this->record($this->shop());

        $html = view('pdf.subscription-invoice', [
            'invoice' => $invoice->load('shop'),
            'shop' => $invoice->shop,
            'logo' => 'data:image/png;base64,AAAA',
            'paymentInstructions' => '',
            'supportContact' => '',
        ])->render();

        $this->assertStringContainsString('data:image/png;base64,AAAA', $html);
        $this->assertStringNotContainsString('.svg', $html);
        $this->assertStringNotContainsString('http', $html);
    }
}
