<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The Reports screen lets a shop pick which of the 10 report-capable
 * screens to look at, then filters/summarizes/exports exactly like that
 * screen's own History page via the shared App\ReportQueries\* classes.
 * These tests cover the screen-picker's own access scoping (the part
 * unique to this screen) and a couple of representative
 * filter-through-to-export checks; the underlying figures themselves are
 * already verified per-class in ReportQueriesTest.
 */
class ReportsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_picker_only_lists_screens_the_role_has_access_to(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['reports', 'sales', 'products']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        Livewire::test('reports.index')
            ->assertViewHas('accessibleScreens', function ($screens) {
                $values = collect($screens)->map(fn ($s) => $s->value);

                return $values->contains('products')
                    && $values->contains('sales')
                    && ! $values->contains('bills')
                    && ! $values->contains('repairs')
                    && ! $values->contains('udhaar');
            });
    }

    public function test_super_admin_disabling_a_module_removes_it_from_the_screen_picker(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'sales');

        $this->actingAs($owner->fresh());

        Livewire::test('reports.index')
            ->assertViewHas('accessibleScreens', fn ($screens) => ! collect($screens)->map(fn ($s) => $s->value)->contains('sales'));
    }

    public function test_a_role_with_no_individual_screen_access_sees_the_empty_state(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['reports']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        Livewire::test('reports.index')
            ->assertViewHas('accessibleScreens', fn ($screens) => count($screens) === 0)
            ->assertSee('No reports available');
    }

    public function test_a_role_without_reports_access_cannot_reach_the_screen_at_all(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('reports.index'))->assertForbidden();
    }

    public function test_switching_screens_resets_filters_from_the_previous_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('reports.index')
            ->set('screen', 'products')
            ->set('search', 'something')
            ->set('screen', 'sales')
            ->assertSet('search', '');
    }

    // ── Filters flow through to export for representative screens ────

    public function test_exporting_products_from_reports_respects_the_active_search_filter(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 200, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Power Bank', 'price' => 3000, 'stock_quantity' => 5, 'details' => ['category' => 'Power Bank']]);

        $this->actingAs($owner);

        Excel::fake();

        Livewire::test('reports.index')
            ->set('screen', 'products')
            ->set('search', 'Cable')
            ->call('exportExcel');

        Excel::matchByRegex();
        Excel::assertDownloaded(
            '/^products-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/',
            fn ($export) => collect($export->array())->last()[0] === 'USB Cable'
        );
    }

    public function test_exporting_sales_from_reports_respects_the_active_date_filter(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $inRange = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        $inRange->created_at = '2026-09-10 10:00:00';
        $inRange->save();

        $outOfRange = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 200, 'discount_amount' => 0, 'total' => 200]);
        $outOfRange->created_at = '2026-08-01 10:00:00';
        $outOfRange->save();

        $this->actingAs($owner);

        $component = Livewire::test('reports.index')
            ->set('screen', 'sales')
            ->set('from', '2026-09-01')
            ->set('to', '2026-09-30');

        $component->assertViewHas('tableRows', fn ($rows) => count($rows) === 1 && $rows[0][4] === 'Rs 100.00');

        Excel::fake();
        $component->call('exportExcel');
        Excel::matchByRegex();
        Excel::assertDownloaded(
            '/^sales-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/',
            fn ($export) => count($export->array()) === 7 // 6 meta/header rows + 1 data row
        );
    }

    public function test_selecting_a_screen_the_role_cannot_access_reverts_to_an_accessible_one(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['reports', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        // Tamper the screen to one this role cannot access, bypassing the dropdown.
        Livewire::test('reports.index')
            ->set('screen', 'bills')
            ->assertSet('screen', 'sales');
    }

    public function test_export_never_produces_a_file_for_a_screen_the_role_cannot_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['reports']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        // No individual screen access at all, so accessibleScreens() is
        // empty and $screen stays '' — reportIfAccessible() must refuse
        // both exports rather than falling through to a default report.
        // abort_if(403) inside a Livewire method doesn't propagate as a
        // raised exception through Livewire::test()->call() (it's absorbed
        // internally, same as documented in ProductSimLegacyTest) — so the
        // guarantee to check is the effect: no file was ever produced.
        Excel::fake();

        Livewire::test('reports.index')->call('exportExcel');

        $property = new \ReflectionProperty(Excel::getFacadeRoot(), 'downloads');
        $property->setAccessible(true);
        $this->assertEmpty($property->getValue(Excel::getFacadeRoot()));
    }

    // ── Tenant isolation ───────────────────────────────────────────────

    public function test_reports_never_leaks_another_shops_data(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'Shop B Product', 'price' => 100, 'stock_quantity' => 1, 'details' => []]);

        $this->actingAs($ownerA);

        Livewire::test('reports.index')
            ->set('screen', 'products')
            ->assertDontSee('Shop B Product');
    }
}
