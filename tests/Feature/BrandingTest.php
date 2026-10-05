<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_layout_renders_the_brand_name(): void
    {
        $shop = Shop::create(['name' => 'Zain Mobile Shop']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->get(route('dashboard'))->assertSee(config('app.name'));
    }

    public function test_guest_layout_renders_the_brand_name(): void
    {
        $this->get(route('login'))->assertSee(config('branding.name'));
    }

    public function test_super_admin_layout_renders_the_brand_name(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $this->actingAs($admin);

        $this->get(route('admin.dashboard'))->assertSee(config('app.name'));
    }

    public function test_sale_invoice_pdf_keeps_the_shops_own_name_as_the_heading_and_adds_the_platform_footer(): void
    {
        $shop = Shop::create(['name' => 'Zain Mobile Shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('Zain Mobile Shop', $html);
        $this->assertStringContainsString(config('branding.powered_by'), $html);
        $this->assertLessThan(
            strpos($html, config('branding.powered_by')),
            strpos($html, 'Zain Mobile Shop'),
            'The shop\'s own name must appear before (and remain the primary heading above) the platform footer line.'
        );
    }

    public function test_a_receipt_pdf_keeps_the_shops_own_name_as_the_heading_and_adds_the_platform_footer(): void
    {
        $shop = Shop::create(['name' => 'Zain Mobile Shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $load = WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id,
            'direction' => 'cash_in', 'provider' => 'JazzCash', 'account_number' => '03001234567',
            'amount' => 500, 'net_amount' => 500, 'fee' => 0, 'discount' => 0, 'total' => 500,
            'payment_status' => 'paid', 'amount_paid' => 500,
        ]);

        $html = view('pdf.wallet-load-receipt', ['load' => $load])->render();

        $this->assertStringContainsString('Zain Mobile Shop', $html);
        $this->assertStringContainsString(config('branding.powered_by'), $html);
        $this->assertLessThan(
            strpos($html, config('branding.powered_by')),
            strpos($html, 'Zain Mobile Shop'),
            'The shop\'s own name must appear before (and remain the primary heading above) the platform footer line.'
        );
    }

    public function test_branding_config_values_drive_the_app_name_and_pdf_footer(): void
    {
        $this->assertSame(config('branding.name'), config('app.name'));

        $shop = Shop::create(['name' => 'Zain Mobile Shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString(config('branding.powered_by'), $html);
    }

    /**
     * Production's .env still has APP_NAME="Mobile Shop POS" and env() would
     * normally override a config fallback — re-evaluates config/app.php
     * directly with that exact env value poisoned, to prove the 'name' key
     * no longer reads env('APP_NAME') at all, regardless of what any
     * deployed .env says.
     */
    public function test_the_app_name_in_config_does_not_depend_on_the_app_name_env_variable(): void
    {
        putenv('APP_NAME=Mobile Shop POS');
        $_ENV['APP_NAME'] = 'Mobile Shop POS';
        $_SERVER['APP_NAME'] = 'Mobile Shop POS';

        $config = require base_path('config/app.php');

        $this->assertSame('HisabOKitab', $config['name']);

        putenv('APP_NAME');
        unset($_ENV['APP_NAME'], $_SERVER['APP_NAME']);
    }

    public function test_the_mail_from_name_in_config_does_not_depend_on_app_name_or_mail_from_name_env_variables(): void
    {
        putenv('APP_NAME=Mobile Shop POS');
        $_ENV['APP_NAME'] = 'Mobile Shop POS';
        putenv('MAIL_FROM_NAME=Mobile Shop POS');
        $_ENV['MAIL_FROM_NAME'] = 'Mobile Shop POS';

        $config = require base_path('config/mail.php');

        $this->assertSame('HisabOKitab', $config['from']['name']);

        putenv('APP_NAME');
        unset($_ENV['APP_NAME'], $_SERVER['APP_NAME']);
        putenv('MAIL_FROM_NAME');
        unset($_ENV['MAIL_FROM_NAME'], $_SERVER['MAIL_FROM_NAME']);
    }

    public function test_a_page_title_is_suffixed_with_the_brand_name(): void
    {
        $shop = Shop::create(['name' => 'Zain Mobile Shop']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->get(route('sales.history'))
            ->assertSee('<title>Sales History — HisabOKitab</title>', false);
    }

    public function test_the_sidebar_wordmark_renders_the_o_in_the_accent_orange_token(): void
    {
        $shop = Shop::create(['name' => 'Zain Mobile Shop']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->get(route('dashboard'))
            ->assertSee('Hisab<span class="text-accent-500">O</span>Kitab', false);
    }
}
