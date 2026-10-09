<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as SaleEditCancelBoundaryTest and others: a
 * wire:click element must live inside its own Livewire component's body,
 * not <x-slot name="header">, or it has no wire:id ancestor to dispatch
 * to and silently does nothing.
 */
class StockInEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithStockIn(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $entry = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 5, 'payment_status' => 'paid', 'amount_paid' => 0, 'stock_date' => '2026-01-10']);
        $this->actingAs($owner);

        return [$shop, $owner, $entry];
    }

    protected function assertMarkupIsInsideComponentBoundary(string $routeName, array $routeParams, string $componentName, string $needle): void
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

    public function test_the_edit_button_on_stock_in_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $entry] = $this->shopWithStockIn();

        $this->assertMarkupIsInsideComponentBoundary('stock-ins.history', [], 'stock-ins.history', "wire:click=\"openEdit({$entry->id})\"");
    }

    public function test_the_cancel_button_on_stock_in_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $entry] = $this->shopWithStockIn();

        $this->assertMarkupIsInsideComponentBoundary('stock-ins.history', [], 'stock-ins.history', "wire:click=\"openCancel({$entry->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_stock_in_historys_own_component_boundary(): void
    {
        [$shop, $owner, $entry] = $this->shopWithStockIn();

        $this->assertMarkupIsInsideComponentBoundary('stock-ins.history', [], 'stock-ins.history', 'edit-stock-in-form');
        $this->assertMarkupIsInsideComponentBoundary('stock-ins.history', [], 'stock-ins.history', 'cancel-stock-in-form');
    }
}
