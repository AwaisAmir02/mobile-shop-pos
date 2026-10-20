<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ShopBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_branding_is_disabled_by_default_for_a_new_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        $this->assertFalse($shop->fresh()->branding_enabled);
    }

    public function test_super_admin_can_turn_branding_on_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleBrandingEnabled');

        $this->assertTrue($shopA->fresh()->branding_enabled);
        $this->assertFalse($shopB->fresh()->branding_enabled);
    }

    public function test_the_branding_tab_is_absent_even_for_the_owner_when_the_shop_feature_is_off(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $html = $this->get(route('settings.index'))->getContent();

        $this->assertStringNotContainsString("setTab('branding')", $html);
        $this->assertStringNotContainsString('saveBranding', $html);
    }

    public function test_the_branding_tab_appears_for_the_owner_once_the_shop_feature_is_on(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $this->get(route('settings.index'))->assertSee("setTab('branding')", false);
    }

    public function test_a_role_without_the_settings_branding_permission_cannot_see_the_tab_even_when_enabled(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $html = $this->get(route('settings.index'))->getContent();

        $this->assertStringNotContainsString("setTab('branding')", $html);
    }

    public function test_a_role_with_the_settings_branding_permission_can_see_the_tab_when_enabled(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings-branding']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertSee("setTab('branding')", false);
    }

    public function test_saving_a_display_name_without_a_logo_updates_the_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'branding')
            ->set('brandingDisplayName', 'Shop A Official Traders')
            ->call('saveBranding')
            ->assertHasNoErrors();

        $this->assertSame('Shop A Official Traders', $shop->fresh()->invoice_display_name);
    }

    public function test_uploading_a_logo_stores_it_under_the_shops_own_folder_and_sets_logo_path(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        $file = UploadedFile::fake()->image('logo.png');

        Livewire::test('settings.index')
            ->set('tab', 'branding')
            ->set('brandingLogo', $file)
            ->call('saveBranding')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertNotNull($shop->logo_path);
        $this->assertStringStartsWith('shop-'.$shop->id.'/branding/', $shop->logo_path);
        Storage::disk('public')->assertExists($shop->logo_path);
    }

    public function test_leaving_the_display_name_blank_clears_any_previous_override(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true, 'invoice_display_name' => 'Old Name']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'branding')
            ->set('brandingDisplayName', '')
            ->call('saveBranding')
            ->assertHasNoErrors();

        $this->assertNull($shop->fresh()->invoice_display_name);
    }

    public function test_saving_branding_is_rejected_server_side_when_the_shop_feature_is_off_even_if_called_directly(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => false]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('brandingDisplayName', 'Should Not Save')
            ->call('saveBranding')
            ->assertStatus(403);
    }

    public function test_invoice_pdf_falls_back_to_the_shops_own_name_when_branding_is_on_but_no_display_name_was_set(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $this->assertSame('Shop A', $shop->invoiceDisplayName());
    }

    public function test_invoice_pdf_uses_the_custom_display_name_when_set(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true, 'invoice_display_name' => 'Shop A Official Traders']);
        $this->assertSame('Shop A Official Traders', $shop->invoiceDisplayName());
    }

    public function test_the_branded_header_partial_shows_the_plain_shop_name_when_branding_is_disabled_regardless_of_stored_fields(): void
    {
        $shop = Shop::create([
            'name' => 'Shop A',
            'branding_enabled' => false,
            'invoice_display_name' => 'Should Not Appear',
        ]);

        $html = view('pdf.partials.branded-shop-name', ['shop' => $shop])->render();

        $this->assertStringContainsString('Shop A', $html);
        $this->assertStringNotContainsString('Should Not Appear', $html);
    }

    public function test_the_branded_header_partial_shows_the_logo_and_custom_name_when_branding_is_enabled(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true, 'invoice_display_name' => 'Custom Name']);
        Storage::disk('public')->put('shop-'.$shop->id.'/branding/logo.png', 'fake-image-content');
        $shop->update(['logo_path' => 'shop-'.$shop->id.'/branding/logo.png']);

        $html = view('pdf.partials.branded-shop-name', ['shop' => $shop])->render();

        $this->assertStringContainsString('Custom Name', $html);
        $this->assertStringContainsString('data:image', $html);
    }

    public function test_the_full_invoice_template_renders_the_logo_and_custom_name_with_branding_enabled(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true, 'invoice_display_name' => 'Custom Name']);
        Storage::disk('public')->put('shop-'.$shop->id.'/branding/logo.png', 'fake-image-content');
        $shop->update(['logo_path' => 'shop-'.$shop->id.'/branding/logo.png']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);

        $html = view('pdf.invoice', ['sale' => $sale])->render();

        $this->assertStringContainsString('Custom Name', $html);
        $this->assertStringContainsString('data:image', $html);
        $this->assertStringNotContainsString('Shop A', $html);
    }

    public function test_the_subscription_invoice_still_shows_hisabokitab_branding_regardless_of_the_shops_own_branding_toggle(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true, 'invoice_display_name' => 'Custom Name']);

        $html = file_get_contents(resource_path('views/pdf/subscription-invoice.blade.php'));

        $this->assertStringContainsString("config('branding.name')", $html);
        $this->assertStringNotContainsString('branded-shop-name', $html);
    }
}
