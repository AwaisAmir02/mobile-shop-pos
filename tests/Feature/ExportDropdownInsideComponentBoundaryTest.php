<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG: the Export dropdown worked on Udhaar but silently did nothing on
 * Sales History, Stock In History, and Party Ledger. Root cause: those
 * three placed <x-ui.export-dropdown /> inside <x-slot name="header">,
 * which layouts.app.blade.php renders via {{ $header }} BEFORE <main>{{
 * $slot }}</main> — i.e. as a sibling of the Livewire component's own
 * wire:id-wrapped root, not a descendant of it. A wire:click element with
 * no wire:id ancestor has no component to dispatch its action to, so the
 * click does nothing. Udhaar's export-dropdown was already outside its
 * header slot (in the component's own body), which is why it worked.
 *
 * This can't be caught by Livewire::test() (it renders the component in
 * isolation, without the surrounding layout), so it drives a real page GET
 * and inspects the raw HTML's byte order — the fix is confirmed by the
 * export-dropdown's markup appearing AFTER the component's own
 * wire:snapshot in the response, i.e. inside its wire:id root.
 */
class ExportDropdownInsideComponentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function assertExportDropdownIsInsideComponentBoundary(string $routeName, string $componentName): void
    {
        $html = $this->get(route($routeName))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);

        $componentSnapshotOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);

            if (($snapshot['memo']['name'] ?? null) === $componentName) {
                $componentSnapshotOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($componentSnapshotOffset, "Could not find a wire:snapshot for [{$componentName}].");

        $exportDropdownOffset = strpos($html, 'Export as PDF');
        $this->assertNotFalse($exportDropdownOffset, 'Export dropdown markup not found on the page.');

        $this->assertGreaterThan(
            $componentSnapshotOffset,
            $exportDropdownOffset,
            "The Export dropdown appears before [{$componentName}]'s own wire:snapshot — it's sitting outside the ".
            'component\'s Livewire boundary (e.g. inside the shared header slot), so wire:click on it has no '.
            'component to dispatch to and silently does nothing.'
        );
    }

    public function test_sales_history_export_dropdown_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertExportDropdownIsInsideComponentBoundary('sales.history', 'sales.history');
    }

    public function test_stock_in_history_export_dropdown_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertExportDropdownIsInsideComponentBoundary('stock-ins.history', 'stock-ins.history');
    }

    public function test_party_ledger_export_dropdown_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertExportDropdownIsInsideComponentBoundary('party-ledger.index', 'party-ledger.index');
    }

    public function test_udhaar_index_export_dropdown_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertExportDropdownIsInsideComponentBoundary('udhaar.index', 'udhaar.index');
    }

    public function test_products_index_export_dropdown_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertExportDropdownIsInsideComponentBoundary('products.index', 'products.index');
    }

    public function test_reports_index_export_dropdown_is_inside_its_own_component_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertExportDropdownIsInsideComponentBoundary('reports.index', 'reports.index');
    }
}
