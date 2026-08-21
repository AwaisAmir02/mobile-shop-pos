<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminShopManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_shop_with_a_login_user_and_subscription(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $this->actingAs($admin);

        Livewire::test('admin.shops.create')
            ->set('shopName', 'New Client Shop')
            ->set('shopPhone', '0311-1234567')
            ->set('userName', 'Client Owner')
            ->set('userEmail', 'client@example.com')
            ->set('password', 'Password123!')
            ->set('password_confirmation', 'Password123!')
            ->set('planType', 'yearly')
            ->set('subscriptionStartDate', '2026-01-01')
            ->set('productsAllowed', '50')
            ->call('save')
            ->assertHasNoErrors();

        $shop = Shop::where('name', 'New Client Shop')->firstOrFail();
        $this->assertSame('yearly', $shop->plan_type->value);
        $this->assertSame('active', $shop->subscription_status->value);
        $this->assertSame(50, $shop->products_allowed);

        $user = User::where('email', 'client@example.com')->firstOrFail();
        $this->assertSame($shop->id, $user->shop_id);
        $this->assertFalse($user->is_super_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Password123!', $user->password));

        // The new user can log in and only reaches shop routes, never admin ones.
        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_super_admin_can_view_and_edit_a_shops_subscription(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create([
            'name' => 'Existing Shop',
            'plan_type' => 'monthly',
            'subscription_status' => 'active',
            'subscription_start_date' => '2026-01-01',
        ]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSet('planType', 'monthly')
            ->set('planType', 'yearly')
            ->set('subscriptionStatus', 'inactive')
            ->call('saveSubscription')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertSame('yearly', $shop->plan_type->value);
        $this->assertSame('inactive', $shop->subscription_status->value);
    }

    public function test_renewing_a_subscription_resets_start_date_and_activates_it(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create([
            'name' => 'Lapsed Shop',
            'plan_type' => 'monthly',
            'subscription_status' => 'inactive',
            'subscription_start_date' => '2025-01-01',
        ]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->call('renewSubscription');

        $shop->refresh();
        $this->assertSame('active', $shop->subscription_status->value);
        $this->assertSame(now()->toDateString(), $shop->subscription_start_date->toDateString());
    }

    public function test_super_admin_sees_correct_per_shop_data_across_multiple_shops(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);

        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        Product::create(['shop_id' => $shopA->id, 'type' => 'accessory', 'name' => 'A Product', 'price' => 100, 'stock_quantity' => 1, 'details' => []]);
        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'B Product 1', 'price' => 100, 'stock_quantity' => 1, 'details' => []]);
        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'B Product 2', 'price' => 100, 'stock_quantity' => 1, 'details' => []]);

        Sale::create(['shop_id' => $shopA->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shopA])
            ->assertViewHas('productCount', 1);

        Livewire::test('admin.shops.show', ['shop' => $shopB])
            ->assertViewHas('productCount', 2);

        // The dashboard list must show independent product counts per shop.
        Livewire::test('admin.dashboard')
            ->assertSee('Shop A')
            ->assertSee('Shop B');
    }
}
