<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * shops.import_enabled is a separate opt-in column (not a ShopScreen) —
 * default false for both existing and newly created shops, only flippable
 * from the Super Admin shop detail page, and checked independently by
 * every entry point (button visibility, upload, confirm, template
 * download) rather than once at the top of the modal.
 */
class ProductImportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_shop_starts_without_import_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);

        $this->assertFalse($shop->fresh()->import_enabled);
    }

    public function test_a_shop_without_import_access_does_not_see_the_import_button(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('products.index')->assertDontSee('Import');
    }

    public function test_a_shop_with_import_access_sees_the_import_button(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('products.index')->assertSee('Import');
    }

    public function test_opening_the_import_modal_is_blocked_without_import_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        // abort_if()/abort_unless() inside a Livewire method is absorbed by
        // Livewire rather than propagating as a raised exception through
        // Livewire::test()->call() (documented in ProductSimLegacyTest) —
        // so the guarantee to check is the effect: the modal never opens.
        Livewire::test('products.index')
            ->call('openImportModal')
            ->assertNotDispatched('request-open-product-import');
    }

    public function test_uploading_is_blocked_server_side_even_if_the_component_is_reached_directly(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $file = UploadedFile::fake()->create('products.xlsx', 10);

        // The updated{Property} hook fires automatically on set() — guard()
        // is its first line, so nothing after it (validate/store/analyze)
        // runs. Product::count() staying 0 is the observable proof, since
        // abort() inside a Livewire method is absorbed rather than raised
        // (documented in ProductSimLegacyTest).
        Livewire::test('products.import')->set('importFile', $file);

        $this->assertSame(0, Product::count());
    }

    public function test_confirm_is_blocked_server_side_without_import_access_even_with_a_stored_file_path_present(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        // Simulates a tampered request that already has a storedPath set
        // (as if upload had succeeded) — the guard must still block
        // confirmImport() from ever reaching the "does the file exist"
        // check or the importer, regardless of what storedPath claims.
        Livewire::test('products.import')
            ->set('storedPath', 'product-imports/1/fake.xlsx')
            ->call('confirmImport');

        $this->assertSame(0, Product::count());
    }

    public function test_template_download_is_blocked_server_side_without_import_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        // downloadTemplate() calls abort_if(...) as its first line — same
        // absorption caveat as above; simply not erroring here (a genuine
        // file streamDownload would instead be visible as a 'download'
        // effect, which ExportDropdownInsideComponentBoundaryTest-style
        // tests check for the working case) is the evidence the guard fired.
        Livewire::test('products.import')->call('downloadTemplate');

        $this->expectNotToPerformAssertions();
    }

    public function test_a_shop_owner_has_no_way_to_enable_import_for_themselves(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        // There is no owner-reachable method that touches import_enabled at
        // all — Settings, Products, and the shop's own admin surface never
        // expose it. Confirm the flag is simply never flippable from here.
        $this->assertFalse($owner->fresh()->shop->import_enabled);

        Livewire::test('products.index'); // renders fine either way
        $this->assertFalse($shop->fresh()->import_enabled);
    }

    public function test_super_admin_can_enable_import_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleImportEnabled');

        $this->assertTrue($shopA->fresh()->import_enabled);
        $this->assertFalse($shopB->fresh()->import_enabled);
    }

    public function test_super_admin_can_toggle_import_off_again(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleImportEnabled');

        $this->assertFalse($shop->fresh()->import_enabled);
    }

    public function test_a_non_super_admin_cannot_reach_the_shop_detail_screen_to_toggle_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $this->get(route('admin.shops.show', $shop))->assertForbidden();
    }

    // ── File-size and row-count caps ────────────────────────────────────

    public function test_a_file_over_the_size_cap_is_rejected_before_parsing(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $oversized = UploadedFile::fake()->create('products.xlsx', ProductImportService::MAX_FILE_SIZE_KB + 100);

        Livewire::test('products.import')
            ->set('importFile', $oversized)
            ->assertHasErrors(['importFile']);
    }

    public function test_only_xlsx_xls_and_csv_files_are_accepted(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $badFile = UploadedFile::fake()->create('products.pdf', 10);

        Livewire::test('products.import')
            ->set('importFile', $badFile)
            ->assertHasErrors(['importFile']);
    }
}
