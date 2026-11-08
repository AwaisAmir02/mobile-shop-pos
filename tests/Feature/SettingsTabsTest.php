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

    public function test_the_owner_lands_on_the_shops_own_settings_not_their_profile(): void
    {
        // Design overhaul D2: Settings used to open on Profile for everyone.
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->assertSet('tab', 'shop-accounts');

        $shop->forceFill(['branding_enabled' => true])->save();
        $this->actingAs($owner->fresh());

        Livewire::test('settings.index')->assertSet('tab', 'branding');
    }

    public function test_someone_who_can_only_reach_their_profile_still_lands_on_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Settings only', 'permissions' => ['settings']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));

        Livewire::test('settings.index')->assertSet('tab', 'profile');
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
            ->call('setTab', 'shop-accounts')
            ->assertSet('tab', 'shop-accounts')
            ->assertSee("Manage the shop's own wallet/bank accounts used to send Wallet Loads, and see how much has moved through each.");
    }

    public function test_the_profile_tab_embeds_the_same_components_as_the_standalone_profile_route(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index', ['tab' => 'profile'])
            ->assertSet('tab', 'profile')
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
