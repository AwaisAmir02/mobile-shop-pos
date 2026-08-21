<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_user_cannot_access_admin_routes(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.shops.create'))->assertForbidden();
    }

    public function test_super_admin_is_redirected_away_from_shop_routes(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);

        $this->actingAs($admin);

        $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
        $this->get(route('products.index'))->assertRedirect(route('admin.dashboard'));
        $this->get(route('sales.index'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_super_admin_can_access_admin_routes(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);

        $this->actingAs($admin);

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.shops.create'))->assertOk();
        $this->get(route('admin.shops.show', $shop))->assertOk();
    }

    public function test_both_roles_can_reach_their_own_profile(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $shopUser = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin)->get(route('profile'))->assertOk();
        $this->actingAs($shopUser)->get(route('profile'))->assertOk();
    }

    public function test_registration_route_no_longer_exists(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
