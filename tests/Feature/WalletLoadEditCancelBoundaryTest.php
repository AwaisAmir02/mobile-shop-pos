<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as SaleEditCancelBoundaryTest and others: a
 * wire:click element must live inside its own Livewire component's body,
 * not <x-slot name="header">, or it has no wire:id ancestor to dispatch
 * to and silently does nothing.
 */
class WalletLoadEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithLoad(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Main', 'provider_type' => 'JazzCash']);
        $load = WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash', 'account_name' => 'x', 'account_number' => 'y', 'shop_account_id' => $account->id, 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'fee_included_in_amount' => false, 'net_amount' => 1000, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);
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

    public function test_the_edit_button_on_wallet_load_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->assertMarkupIsInsideComponentBoundary('wallet-loads.history', [], 'wallet-loads.history', "wire:click=\"openEdit({$load->id})\"");
    }

    public function test_the_cancel_button_on_wallet_load_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->assertMarkupIsInsideComponentBoundary('wallet-loads.history', [], 'wallet-loads.history', "wire:click=\"openCancel({$load->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_wallet_load_historys_own_component_boundary(): void
    {
        [$shop, $owner, $load] = $this->shopWithLoad();

        $this->assertMarkupIsInsideComponentBoundary('wallet-loads.history', [], 'wallet-loads.history', 'edit-wallet-load-form');
        $this->assertMarkupIsInsideComponentBoundary('wallet-loads.history', [], 'wallet-loads.history', 'cancel-wallet-load-form');
    }
}
