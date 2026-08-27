<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_defaults_to_the_accessory_categories_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->assertSet('tab', 'accessory-categories');
    }

    public function test_switching_tabs_shows_the_right_content(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('setTab', 'networks')
            ->assertSet('tab', 'networks')
            ->assertSee('Manage the networks available on the Balance Load screen.');

        Livewire::test('settings.index')
            ->call('setTab', 'wallet-providers')
            ->assertSet('tab', 'wallet-providers')
            ->assertSee('Manage the providers available on the Wallet Load screen.');
    }

    public function test_the_profile_tab_embeds_the_same_components_as_the_standalone_profile_route(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('setTab', 'profile')
            ->assertSeeLivewire('profile.update-profile-information-form')
            ->assertSeeLivewire('profile.update-password-form')
            ->assertSeeLivewire('profile.delete-user-form');

        $this->get(route('profile'))
            ->assertSeeLivewire('profile.update-profile-information-form');
    }

    public function test_deep_linking_to_a_specific_tab_via_query_string_works(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $this->get(route('settings.index').'?tab=roles')->assertOk();
    }

    public function test_a_staff_role_without_settings_or_users_access_cannot_reach_settings_at_all(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertForbidden();
    }
}
