<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardAccessLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_dashboard_access_lands_on_the_real_dashboard_after_login(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'password' => bcrypt('password')]);

        Volt::test('pages.auth.login')
            ->set('form.email', $owner->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_user_without_dashboard_access_lands_on_the_welcome_page_after_login_instead_of_a_403(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'password' => bcrypt('password'),
        ]);

        Volt::test('pages.auth.login')
            ->set('form.email', $staff->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('welcome', absolute: false));

        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('Welcome, '.$staff->name);
        $response->assertSee('Cashier');
    }

    public function test_the_welcome_page_is_reachable_directly_by_url_without_any_specific_permission(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('welcome'))->assertOk();
    }

    public function test_a_user_with_dashboard_access_is_redirected_away_from_the_welcome_page_to_the_real_dashboard(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $this->get(route('welcome'))->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_the_root_route_sends_a_user_with_dashboard_access_to_the_dashboard_and_without_to_welcome(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($owner);
        $this->get('/')->assertRedirect(route('dashboard', absolute: false));

        $this->actingAs($staff);
        $this->get('/')->assertRedirect(route('welcome', absolute: false));
    }

    public function test_a_role_without_settings_or_dashboard_access_still_reaches_welcome_not_a_403(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('welcome'))->assertOk();
    }

    public function test_a_shop_owner_loses_dashboard_access_and_lands_on_welcome_when_super_admin_disables_the_module(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'dashboard');

        $this->assertTrue($shop->fresh()->isScreenDisabled('dashboard'));

        $this->actingAs($owner->fresh());

        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('welcome'))->assertOk();
        $this->get('/')->assertRedirect(route('welcome', absolute: false));
    }
}
