<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same regression class as BillPaymentEditCancelBoundaryTest and others: a
 * wire:click element must live inside its own Livewire component's body,
 * not <x-slot name="header">, or it has no wire:id ancestor to dispatch
 * to and silently does nothing.
 */
class ServicePaymentEditCancelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithServicePayment(): array
    {
        $shop = Shop::create(['name' => 'Shop A', 'services_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $category = ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Installations']);
        $item = ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $category->id, 'name' => 'Screen Guard']);
        $payment = ServicePayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'service_category_id' => $category->id, 'service_item_id' => $item->id, 'amount' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);
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

    public function test_the_edit_button_on_services_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithServicePayment();

        $this->assertMarkupIsInsideComponentBoundary('services.history', [], 'services.history', "wire:click=\"openEdit({$payment->id})\"");
    }

    public function test_the_cancel_button_on_services_history_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithServicePayment();

        $this->assertMarkupIsInsideComponentBoundary('services.history', [], 'services.history', "wire:click=\"openCancel({$payment->id})\"");
    }

    public function test_the_edit_and_cancel_modals_are_inside_services_historys_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithServicePayment();

        $this->assertMarkupIsInsideComponentBoundary('services.history', [], 'services.history', 'edit-service-payment-form');
        $this->assertMarkupIsInsideComponentBoundary('services.history', [], 'services.history', 'cancel-service-payment-form');
    }

    public function test_the_add_to_receipt_button_on_services_create_is_inside_its_own_component_boundary(): void
    {
        [$shop, $owner, $payment] = $this->shopWithServicePayment();

        $this->assertMarkupIsInsideComponentBoundary('services.index', [], 'services.create', 'wire:submit="save"');
    }
}
