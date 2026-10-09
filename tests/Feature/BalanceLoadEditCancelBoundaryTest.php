<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as SaleEditCancelBoundaryTest and others: a
 * wire:click element must live inside its own Livewire component's body,
 * not <x-slot name="header">, or it has no wire:id ancestor to dispatch
 * to and silently does nothing.
 */
class BalanceLoadEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithLoad(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $load = BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'fee' => 0, 'discount' => 0, 'total' => 500, 'payment_status' => 'paid', 'amount_paid' => 500]);
        $this->actingAs($owner);

        return [$shop, $owner, $load];
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

    public function test_the_edit_button_on_balance_load_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->assertMarkupIsInsideComponentBoundary('balance-loads.history', [], 'balance-loads.history', "wire:click=\"openEdit({$load->id})\"");
    }

    public function test_the_cancel_button_on_balance_load_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->assertMarkupIsInsideComponentBoundary('balance-loads.history', [], 'balance-loads.history', "wire:click=\"openCancel({$load->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_balance_load_historys_own_component_boundary(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->assertMarkupIsInsideComponentBoundary('balance-loads.history', [], 'balance-loads.history', 'edit-balance-load-form');
        $this->assertMarkupIsInsideComponentBoundary('balance-loads.history', [], 'balance-loads.history', 'cancel-balance-load-form');
    }
}
