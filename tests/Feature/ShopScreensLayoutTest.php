<?php

namespace Tests\Feature;

use App\Enums\WhatsAppOrderStatus;
use App\Models\Shop;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Layout pins for the shop and account screens. Wording and
 * figures are covered by each screen's own tests.
 */
class ShopScreensLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Shop A', 'whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id]));
    }

    public function test_a_confirmed_submit_button_still_submits_its_form(): void
    {
        $html = Blade::render('<form wire:submit="reject"><x-ui.button type="submit" confirm="Reject this order?">Reject</x-ui.button></form>');

        // The visible button keeps its type, so Enter in the form reaches it, and asks first.
        $this->assertMatchesRegularExpression('/<button\s+type="submit"[^>]*x-on:click\.prevent="[^"]*\$store\.confirm\.ask/', $html);
        // On yes, its twin submits the form.
        $this->assertStringContainsString('<button type="submit" hidden tabindex="-1" aria-hidden="true" data-confirm-twin', $html);
    }

    public function test_an_ordinary_confirmed_button_keeps_a_plain_twin(): void
    {
        $html = Blade::render('<x-ui.button wire:click="remove(3)" confirm="Remove?">Remove</x-ui.button>');

        $this->assertStringContainsString('<button type="button" hidden tabindex="-1" aria-hidden="true" data-confirm-twin wire:click="remove(3)"', $html);
    }

    public function test_dashboard_folds_empty_counter_rows_but_keeps_them_in_the_page(): void
    {
        Livewire::test('dashboard.index')
            ->assertSee('Balance Loaded')
            ->assertSee('NADRA Verifications')
            ->assertSeeHtml('data-quiet')
            ->assertSee('with nothing this period');
    }

    public function test_order_inbox_is_a_segmented_switch_and_its_table_stacks_on_phones(): void
    {
        $contact = WhatsAppContact::create(['shop_id' => $this->shop->id, 'wa_id' => '923001112223', 'last_inbound_at' => now()]);
        WhatsAppOrder::create(['shop_id' => $this->shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now()]);

        Livewire::test('whatsapp-orders.index')
            ->assertSeeHtml('aria-pressed="true"')
            ->assertSeeHtml("wire:click=\"setTab('accepted')\"")
            ->assertSeeHtml('ui-stack')
            ->assertSeeHtml('data-label="Customer"');
    }

    public function test_plan_and_usage_keeps_every_feature_in_the_page(): void
    {
        $page = Livewire::test('billing.index')->assertSee('no payment due');
        $features = $page->viewData('features');

        foreach ($features as $feature) {
            $page->assertSee($feature['label']);
        }

        if (count($features) > 8) {
            $page->assertSee('Show all '.count($features));
        }
    }
}
