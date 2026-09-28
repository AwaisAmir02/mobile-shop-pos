<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as ExportDropdownInsideComponentBoundaryTest, for
 * the same reason: the Import button and its modal must live inside the
 * products.index component's own body, not <x-slot name="header">, or
 * wire:click="openImportModal" has no wire:id ancestor to dispatch to and
 * silently does nothing — exactly the bug that once broke the Export
 * dropdown on three other screens.
 */
class ProductImportModalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_import_button_is_inside_the_products_components_own_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'import_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $html = $this->get(route('products.index'))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);

        $componentSnapshotOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);

            if (($snapshot['memo']['name'] ?? null) === 'products.index') {
                $componentSnapshotOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($componentSnapshotOffset, 'Could not find a wire:snapshot for [products.index].');

        $importButtonOffset = strpos($html, 'wire:click="openImportModal"');
        $this->assertNotFalse($importButtonOffset, 'Import button markup not found on the page.');

        $this->assertGreaterThan(
            $componentSnapshotOffset,
            $importButtonOffset,
            'The Import button appears before products.index\'s own wire:snapshot — it\'s sitting outside the '.
            'component\'s Livewire boundary, so wire:click on it has no component to dispatch to and silently does nothing.'
        );
    }

    public function test_the_import_modal_component_is_only_rendered_when_the_shop_has_import_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']); // import_enabled defaults to false
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $html = $this->get(route('products.index'))->getContent();

        $this->assertStringNotContainsString('wire:click="openImportModal"', $html);
    }
}
