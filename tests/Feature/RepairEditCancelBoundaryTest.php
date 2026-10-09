<?php

namespace Tests\Feature;

use App\Models\Repair;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as SaleEditCancelBoundaryTest and
 * ExpenseEditCancelBoundaryTest: a wire:click element must live inside its
 * own Livewire component's body, not <x-slot name="header">, or it has no
 * wire:id ancestor to dispatch to and silently does nothing.
 */
class RepairEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithRepair(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $repair = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'x', 'amount' => 1000, 'discount' => 0, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);
        $this->actingAs($owner);

        return [$shop, $owner, $repair];
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

    public function test_the_edit_button_on_repairs_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        $this->assertMarkupIsInsideComponentBoundary('repairs.history', [], 'repairs.history', "wire:click=\"openEdit({$repair->id})\"");
    }

    public function test_the_cancel_button_on_repairs_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        $this->assertMarkupIsInsideComponentBoundary('repairs.history', [], 'repairs.history', "wire:click=\"openCancel({$repair->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_repairs_historys_own_component_boundary(): void
    {
        [$shop, $owner, $repair] = $this->shopWithRepair();

        $this->assertMarkupIsInsideComponentBoundary('repairs.history', [], 'repairs.history', 'edit-repair-form');
        $this->assertMarkupIsInsideComponentBoundary('repairs.history', [], 'repairs.history', 'cancel-repair-form');
    }
}
