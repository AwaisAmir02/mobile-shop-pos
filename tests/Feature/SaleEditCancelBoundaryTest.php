<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
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
class SaleEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithSale(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);
        $this->actingAs($owner);

        return [$shop, $owner, $sale];
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

    public function test_the_delete_button_on_the_sale_show_page_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sales.show', [$sale], 'sales.show', 'wire:click="openCancel"');
    }

    public function test_the_cancel_sale_modal_is_inside_the_sale_show_pages_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sales.show', [$sale], 'sales.show', 'cancel-sale-form');
    }

    public function test_the_delete_button_on_sales_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sales.history', [], 'sales.history', "wire:click=\"openCancel({$sale->id})\"");
    }

    public function test_the_save_changes_button_on_edit_sale_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sales.edit', [$sale], 'sales.edit', 'wire:click="saveChanges"');
    }
}
