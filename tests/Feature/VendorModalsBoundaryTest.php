<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as ExportDropdownInsideComponentBoundaryTest, for
 * the same reason: a wire:click/wire:submit element must live inside its
 * own Livewire component's body, not <x-slot name="header">, or it has no
 * wire:id ancestor to dispatch to and silently does nothing.
 */
class VendorModalsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function assertMarkupIsInsideComponentBoundary(string $routeName, string $componentName, string $needle, array $routeParams = []): void
    {
        $html = $this->get(route($routeName, $routeParams))->getContent();

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

        $needleOffset = strpos($html, $needle);
        $this->assertNotFalse($needleOffset, "Markup \"{$needle}\" not found on the page.");

        $this->assertGreaterThan(
            $componentSnapshotOffset,
            $needleOffset,
            "\"{$needle}\" appears before [{$componentName}]'s own wire:snapshot — it's sitting outside the ".
            'component\'s Livewire boundary, so wire:click/wire:submit on it has no component to dispatch to and silently does nothing.'
        );
    }

    public function test_the_add_vendor_button_is_inside_the_vendors_index_components_own_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertMarkupIsInsideComponentBoundary('vendors.index', 'vendors.index', 'wire:click="openCreate"');
    }

    public function test_the_vendor_quick_create_modal_is_inside_the_stock_ins_create_components_own_boundary(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->assertMarkupIsInsideComponentBoundary('stock-ins.index', 'stock-ins.create', 'quick-create-vendor');
    }
}
