<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SimSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as SaleEditCancelBoundaryTest and others: a
 * wire:click element must live inside its own Livewire component's body,
 * not <x-slot name="header">, or it has no wire:id ancestor to dispatch
 * to and silently does nothing.
 */
class SimSaleEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithSale(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $sale = SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'is_duplicate' => false, 'sim_number' => 'x', 'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200, 'payment_status' => 'paid', 'amount_paid' => 200]);
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

    public function test_the_edit_button_on_sim_sale_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sim-sales.history', [], 'sim-sales.history', "wire:click=\"openEdit({$sale->id})\"");
    }

    public function test_the_cancel_button_on_sim_sale_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sim-sales.history', [], 'sim-sales.history', "wire:click=\"openCancel({$sale->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_sim_sale_historys_own_component_boundary(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();

        $this->assertMarkupIsInsideComponentBoundary('sim-sales.history', [], 'sim-sales.history', 'edit-sim-sale-form');
        $this->assertMarkupIsInsideComponentBoundary('sim-sales.history', [], 'sim-sales.history', 'cancel-sim-sale-form');
    }
}
