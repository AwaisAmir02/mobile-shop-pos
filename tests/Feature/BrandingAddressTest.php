<?php

namespace Tests\Feature;

use App\Models\Repair;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_an_address_in_branding_updates_invoice_address(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'branding')
            ->set('brandingDisplayName', 'Shop A Traders')
            ->set('brandingAddress', 'Shop # 12, Star City Mall, Saddar, Karachi')
            ->call('saveBranding')
            ->assertHasNoErrors();

        $this->assertSame('Shop # 12, Star City Mall, Saddar, Karachi', $shop->fresh()->invoice_address);
        $this->assertSame('Shop # 12, Star City Mall, Saddar, Karachi', $shop->fresh()->invoiceAddress());
    }

    public function test_leaving_branding_address_blank_clears_it(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'branding_enabled' => true,
            'invoice_address' => 'Old Address',
        ]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'branding')
            ->set('brandingAddress', '')
            ->call('saveBranding')
            ->assertHasNoErrors();

        $this->assertNull($shop->fresh()->invoice_address);
        $this->assertNull($shop->fresh()->invoiceAddress());
    }

    public function test_branding_address_rejects_more_than_500_characters(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'branding')
            ->set('brandingAddress', str_repeat('a', 501))
            ->call('saveBranding')
            ->assertHasErrors(['brandingAddress']);
    }

    public function test_invoice_address_does_not_fall_back_to_shops_address_when_blank(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'branding_enabled' => true,
            'address' => 'Physical Street Address',
            'invoice_address' => null,
        ]);

        $this->assertNull($shop->invoiceAddress());
    }

    public function test_invoice_address_returns_null_when_branding_is_disabled_even_if_invoice_address_is_stored(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'branding_enabled' => false,
            'invoice_address' => 'Some Stored Address',
        ]);

        $this->assertNull($shop->invoiceAddress());
    }

    public function test_branded_shop_name_partial_renders_address_when_set(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'branding_enabled' => true,
            'invoice_address' => 'Suite 5, Technocenter, Karachi',
        ]);

        $html = view('pdf.partials.branded-shop-name', ['shop' => $shop])->render();

        $this->assertStringContainsString('Suite 5, Technocenter, Karachi', $html);
        $this->assertStringContainsString('shop-address', $html);
    }

    public function test_branded_shop_name_partial_omits_address_when_blank(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'branding_enabled' => true,
            'invoice_address' => null,
        ]);

        $html = view('pdf.partials.branded-shop-name', ['shop' => $shop])->render();

        $this->assertStringNotContainsString('shop-address', $html);
    }

    public function test_repair_receipt_includes_branding_address_via_partial_without_showing_phone(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'phone' => '03001234567',
            'branding_enabled' => true,
            'invoice_address' => 'Plaza 4, Repair Lane, Lahore',
        ]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $repair = Repair::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'category' => 'mobile',
            'description' => 'Fix jack',
            'amount' => 500,
            'discount' => 0,
            'total' => 500,
            'payment_status' => 'paid',
            'amount_paid' => 500,
        ]);

        $html = view('pdf.repair-receipt', ['repair' => $repair])->render();

        $this->assertStringContainsString('Plaza 4, Repair Lane, Lahore', $html);
        $this->assertStringNotContainsString('03001234567', $html);
    }

    public function test_sales_invoice_renders_branding_address_and_phone(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'phone' => '03001234567',
            'branding_enabled' => true,
            'invoice_address' => 'Shop 9, Mobile Plaza, Saddar',
        ]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $sale = Sale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ]);

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('Shop 9, Mobile Plaza, Saddar', $html);
        $this->assertStringContainsString('03001234567', $html);
    }
}
