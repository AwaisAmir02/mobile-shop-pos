<?php

namespace Tests\Feature;

use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as SaleEditCancelBoundaryTest and others: a
 * wire:click element must live inside its own Livewire component's body,
 * not <x-slot name="header">, or it has no wire:id ancestor to dispatch
 * to and silently does nothing.
 */
class BillPaymentEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithBillPayment(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Main', 'provider_type' => 'JazzCash']);
        $payment = BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id, 'shop_account_id' => $account->id, 'consumer_number' => 'x', 'consumer_name' => 'y', 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);
        $this->actingAs($owner);

        return [$shop, $owner, $payment];
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

    public function test_the_edit_button_on_bills_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        $this->assertMarkupIsInsideComponentBoundary('bills.history', [], 'bills.history', "wire:click=\"openEdit({$payment->id})\"");
    }

    public function test_the_cancel_button_on_bills_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        $this->assertMarkupIsInsideComponentBoundary('bills.history', [], 'bills.history', "wire:click=\"openCancel({$payment->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_bills_historys_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithBillPayment();

        $this->assertMarkupIsInsideComponentBoundary('bills.history', [], 'bills.history', 'edit-bill-payment-form');
        $this->assertMarkupIsInsideComponentBoundary('bills.history', [], 'bills.history', 'cancel-bill-payment-form');
    }
}
