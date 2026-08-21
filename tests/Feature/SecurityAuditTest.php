<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_reject_unauthorized_requests_over_real_http(): void
    {
        // Livewire's AJAX follow-up requests all share a single generic
        // '/livewire/update' route with only 'web' middleware — the actual
        // enforcement is that a shop user can never legitimately render the
        // admin component in the first place (no signed snapshot is ever
        // issued to them), which is what these real HTTP requests confirm.
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/shops/create')->assertForbidden();
        $this->get('/admin/shops/'.$shop->id)->assertForbidden();
    }

    public function test_forgot_password_gives_an_identical_response_whether_or_not_the_account_exists(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        User::factory()->create(['shop_id' => $shop->id, 'email' => 'real@example.com']);

        // Neither path may add a field error — an error appearing only for one
        // of the two would itself be an enumeration signal to the frontend.
        Livewire::test('pages.auth.forgot-password')
            ->set('email', 'real@example.com')
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors()
            ->assertSet('email', '');

        Livewire::test('pages.auth.forgot-password')
            ->set('email', 'nobody-here@example.com')
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors()
            ->assertSet('email', '');
    }

    public function test_forgot_password_view_only_ever_renders_the_generic_sent_message(): void
    {
        // The view no longer branches on the broker status at all — assert
        // the code path that used to render the account-existence-leaking
        // message ($status != RESET_LINK_SENT) is gone from the component.
        $source = file_get_contents(resource_path('views/livewire/pages/auth/forgot-password.blade.php'));

        $this->assertStringNotContainsString('RESET_LINK_SENT', $source);
        $this->assertStringContainsString("__('passwords.sent')", $source);
    }

    public function test_editing_a_product_with_a_tampered_id_never_touches_another_shops_product(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $userA = User::factory()->create(['shop_id' => $shopA->id]);

        $otherShopsProduct = Product::create([
            'shop_id' => $shopB->id,
            'type' => 'accessory',
            'name' => 'Shop B Product',
            'price' => 999,
            'stock_quantity' => 5,
            'details' => [],
        ]);

        $this->actingAs($userA);

        // Simulate a tampered editingId pointing at another shop's real product.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('products.index')
            ->set('editingId', $otherShopsProduct->id)
            ->set('type', 'accessory')
            ->set('name', 'Hijacked Name')
            ->set('price', '1')
            ->set('stock_quantity', '1')
            ->set('category', 'cable')
            ->call('save');
    }

    public function test_products_can_still_be_created_and_edited_normally(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->set('name', 'Cable')
            ->set('price', '100')
            ->set('stock_quantity', '10')
            ->set('category', 'cable')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Cable')->firstOrFail();
        $this->assertSame($shop->id, $product->shop_id);

        Livewire::test('products.index')
            ->set('editingId', $product->id)
            ->set('type', 'accessory')
            ->set('name', 'Cable v2')
            ->set('price', '150')
            ->set('stock_quantity', '20')
            ->set('category', 'cable')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Cable v2', $product->fresh()->name);
        $this->assertSame(1, Product::count());
    }
}
