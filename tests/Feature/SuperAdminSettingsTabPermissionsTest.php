<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminSettingsTabPermissionsTest extends TestCase
{
    use RefreshDatabase;

    // ── Each Settings tab is independently gated ─────────────────────

    public function test_super_admin_can_disable_just_one_settings_tab_while_the_others_stay_reachable(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'settings-networks');

        $this->actingAs($owner->fresh());

        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', function ($tabs) {
                return in_array('accessory-categories', $tabs, true)
                    && in_array('bills', $tabs, true)
                    && in_array('percentage', $tabs, true)
                    && ! in_array('networks', $tabs, true);
            });
    }

    public function test_each_of_the_four_new_tab_screens_can_be_disabled_independently(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);

        foreach (['settings-categories', 'settings-networks', 'settings-bill-config', 'settings-percentage'] as $screen) {
            $this->actingAs($admin);
            $component = Livewire::test('admin.shops.show', ['shop' => $shop]);
            $component->call('toggleScreen', $screen);

            $this->assertTrue($shop->fresh()->isScreenDisabled($screen));

            // Every other one of the four stays enabled.
            foreach (['settings-categories', 'settings-networks', 'settings-bill-config', 'settings-percentage'] as $other) {
                if ($other !== $screen) {
                    $this->assertFalse($shop->fresh()->isScreenDisabled($other), "$other should remain enabled while only $screen is disabled.");
                }
            }

            // Re-enable before testing the next one.
            $component->call('toggleScreen', $screen);
        }
    }

    public function test_disabling_a_settings_tab_blocks_its_own_methods_even_for_the_owner(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'settings-percentage');

        $this->actingAs($owner->fresh());

        Livewire::test('settings.index')
            ->set('simSaleCommissionPercent', '5')
            ->call('saveCommissionPercentages');

        $this->assertNull($shop->fresh()->sim_sale_commission_percent);
    }

    // ── Role grants never override a Super Admin platform-wide disable ─

    public function test_a_settings_tab_granted_by_the_role_but_disabled_by_super_admin_is_blocked(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings-categories']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);
        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => in_array('accessory-categories', $tabs, true));

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'settings-categories');

        $this->actingAs($staff->fresh());
        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => ! in_array('accessory-categories', $tabs, true));
    }

    public function test_a_settings_tab_enabled_by_super_admin_but_not_granted_by_the_role_is_still_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->assertFalse($shop->fresh()->isScreenDisabled('settings-networks'));

        $this->actingAs($staff);

        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => ! in_array('networks', $tabs, true));
    }

    // ── The redesigned Module Access grid still works for every screen ─

    public function test_the_redesigned_grid_still_toggles_a_pre_existing_screen_correctly(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'dashboard');

        $this->assertTrue($shop->fresh()->isScreenDisabled('dashboard'));
    }

    public function test_the_grid_shows_every_screen_including_the_four_new_ones_grouped_by_section(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSee('Core')
            ->assertSee('Financial Services')
            ->assertSee('Administration')
            ->assertSee('Settings')
            ->assertSee('Settings: Categories')
            ->assertSee('Settings: Networks')
            ->assertSee('Settings: Bill Providers')
            ->assertSee('Settings: Percentage')
            ->assertSee('Dashboard')
            ->assertSee('Party Ledger');
    }

    public function test_toggling_a_module_twice_via_the_redesigned_grid_re_enables_it(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);

        $this->actingAs($admin);
        $component = Livewire::test('admin.shops.show', ['shop' => $shop]);

        $component->call('toggleScreen', 'settings-bill-config');
        $this->assertTrue($shop->fresh()->isScreenDisabled('settings-bill-config'));

        $component->call('toggleScreen', 'settings-bill-config');
        $this->assertFalse($shop->fresh()->isScreenDisabled('settings-bill-config'));
    }

    // ── Backfill migration ─────────────────────────────────────────────

    public function test_the_backfill_migration_adds_granular_permissions_to_roles_that_had_settings(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $roleWithSettings = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings', 'sales']]);
        $roleWithoutSettings = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);

        $migration = require database_path('migrations/2026_09_17_090000_backfill_granular_settings_permissions_on_roles_table.php');
        $migration->up();

        $this->assertEqualsCanonicalizing(
            ['settings', 'sales', 'settings-categories', 'settings-networks', 'settings-bill-config', 'settings-percentage'],
            $roleWithSettings->fresh()->permissions
        );
        $this->assertEqualsCanonicalizing(['sales'], $roleWithoutSettings->fresh()->permissions);
    }

    public function test_the_backfill_migration_is_idempotent(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings']]);

        $migration = require database_path('migrations/2026_09_17_090000_backfill_granular_settings_permissions_on_roles_table.php');
        $migration->up();
        $migration->up();

        $permissions = $role->fresh()->permissions;
        $this->assertEqualsCanonicalizing(
            ['settings', 'settings-categories', 'settings-networks', 'settings-bill-config', 'settings-percentage'],
            $permissions
        );
        $this->assertCount(5, $permissions);
    }

    public function test_the_backfill_migration_down_strips_the_granular_permissions_back_out(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings', 'sales']]);

        $migration = require database_path('migrations/2026_09_17_090000_backfill_granular_settings_permissions_on_roles_table.php');
        $migration->up();
        $migration->down();

        $this->assertEqualsCanonicalizing(['settings', 'sales'], $role->fresh()->permissions);
    }

    // ── New Roles created after this ships behave normally ────────────

    public function test_a_role_created_after_the_fact_is_not_auto_granted_the_granular_permissions(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('roleName', 'New Manager')
            ->set('rolePermissions', ['settings-categories'])
            ->call('saveRole')
            ->assertHasNoErrors();

        $role = Role::where('name', 'New Manager')->firstOrFail();

        $this->assertEqualsCanonicalizing(['settings-categories'], $role->permissions);
    }
}
