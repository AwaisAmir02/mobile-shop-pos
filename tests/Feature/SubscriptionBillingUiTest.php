<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionBillingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
    }

    protected function shop(array $attributes = []): Shop
    {
        return Shop::create(array_merge(['name' => 'Zain Mobile Shop', 'plan_type' => 'monthly'], $attributes));
    }

    protected function record(Shop $shop, array $overrides = []): SubscriptionInvoice
    {
        return app(SubscriptionBillingService::class)->create($shop, array_merge([
            'period_start' => '2026-10-01', 'months' => 1, 'amount' => 1000, 'discount' => 0, 'notes' => null,
        ], $overrides), null);
    }

    public function test_adding_a_record_creates_it_with_the_derived_end_and_total(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCreate')
            ->set('periodStart', '2026-10-01')
            ->set('months', '3')
            ->set('amount', '1500')
            ->set('discount', '100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('subscription_invoices', [
            'shop_id' => $shop->id, 'months' => 3, 'period_end' => '2026-12-31', 'total' => 1400.00, 'status' => 'unpaid',
        ]);
    }

    public function test_the_add_form_pre_fills_the_start_date_from_the_subscription_and_months_from_the_plan(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop(['plan_type' => 'yearly', 'subscription_start_date' => '2026-02-14']);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->assertSet('periodStart', '2026-02-14')
            ->assertSet('months', '12');
    }

    public function test_the_mark_paid_checkbox_creates_the_record_already_paid_with_the_date(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCreate')
            ->set('periodStart', '2026-10-01')
            ->set('months', '1')
            ->set('amount', '800')
            ->set('markPaid', true)
            ->set('paidAt', '2026-10-03')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('subscription_invoices', ['shop_id' => $shop->id, 'status' => 'paid', 'paid_at' => '2026-10-03']);
    }

    public function test_a_discount_larger_than_the_amount_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCreate')
            ->set('periodStart', '2026-10-01')
            ->set('months', '1')
            ->set('amount', '100')
            ->set('discount', '150')
            ->call('save')
            ->assertHasErrors(['discount']);

        $this->assertSame(0, SubscriptionInvoice::count());
    }

    public function test_a_negative_amount_and_zero_months_are_rejected(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCreate')
            ->set('periodStart', '2026-10-01')
            ->set('months', '0')
            ->set('amount', '-5')
            ->call('save')
            ->assertHasErrors(['months', 'amount']);

        $this->assertSame(0, SubscriptionInvoice::count());
    }

    public function test_months_above_the_limit_are_rejected(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCreate')
            ->set('periodStart', '2026-10-01')
            ->set('months', '121')
            ->set('amount', '10')
            ->call('save')
            ->assertHasErrors(['months']);
    }

    public function test_editing_a_record_updates_it(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $invoice = $this->record($shop);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openEdit', $invoice->id)
            ->set('amount', '2000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2000.00', (string) $invoice->fresh()->total);
    }

    public function test_a_cancelled_record_cannot_be_opened_for_editing(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $invoice = $this->record($shop);
        app(SubscriptionBillingService::class)->cancel($invoice, 'Mistake');

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openEdit', $invoice->id)
            ->assertDispatched('toast', type: 'error');
    }

    public function test_mark_paid_and_mark_unpaid_toggle_the_status(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $invoice = $this->record($shop);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])->call('markPaid', $invoice->id);
        $this->assertSame('paid', $invoice->fresh()->status->value);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])->call('markUnpaid', $invoice->id);
        $this->assertSame('unpaid', $invoice->fresh()->status->value);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $invoice = $this->record($shop);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCancel', $invoice->id)
            ->set('cancelReason', '')
            ->call('cancelInvoice')
            ->assertHasErrors(['cancelReason']);

        $this->assertSame('unpaid', $invoice->fresh()->status->value);
    }

    public function test_cancel_with_a_reason_cancels_and_keeps_the_record(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $invoice = $this->record($shop);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCancel', $invoice->id)
            ->set('cancelReason', 'Billed twice')
            ->call('cancelInvoice')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $invoice->fresh()->status->value);
        $this->assertSame('Billed twice', $invoice->fresh()->cancel_reason);
        $this->assertDatabaseCount('subscription_invoices', 1);
    }

    public function test_the_billing_card_has_no_hard_delete_action(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $this->record($shop);

        $html = Livewire::test('admin.shops.billing', ['shopId' => $shop->id])->html();

        $this->assertStringNotContainsString('wire:click="delete', $html);
        $this->assertStringNotContainsString('deleteInvoice', $html);
    }

    public function test_the_existing_renew_now_action_still_only_touches_the_subscription(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop(['subscription_status' => 'inactive']);
        $this->record($shop);

        Livewire::test('admin.shops.show', ['shop' => $shop])->call('renewSubscription');

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->subscription_status->value);
        $this->assertSame(1, SubscriptionInvoice::count());
    }

    public function test_creating_a_billing_record_leaves_the_subscription_card_fields_untouched(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop(['plan_type' => 'monthly', 'subscription_status' => 'inactive', 'subscription_start_date' => '2026-01-01']);

        Livewire::test('admin.shops.billing', ['shopId' => $shop->id])
            ->call('openCreate')
            ->set('periodStart', '2026-10-01')
            ->set('months', '12')
            ->set('amount', '5000')
            ->call('save');

        $fresh = $shop->fresh();
        $this->assertSame('monthly', $fresh->plan_type->value);
        $this->assertSame('inactive', $fresh->subscription_status->value);
        $this->assertSame('2026-01-01', $fresh->subscription_start_date->toDateString());
    }

    public function test_the_existing_save_subscription_action_still_works_alongside_billing(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop(['plan_type' => 'monthly']);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->set('planType', 'yearly')
            ->set('subscriptionStatus', 'active')
            ->call('saveSubscription')
            ->assertHasNoErrors();

        $fresh = $shop->fresh();
        $this->assertSame('yearly', $fresh->plan_type->value);
        $this->assertSame('active', $fresh->subscription_status->value);
    }

    public function test_a_shop_user_cannot_reach_the_billing_card_or_its_pdf_route(): void
    {
        $shop = $this->shop();
        $invoice = $this->record($shop);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));

        $this->get(route('admin.shops.show', $shop))->assertForbidden();
        $this->get(route('admin.shops.billing.invoices.pdf', ['shop' => $shop->id, 'invoice' => $invoice->id]))->assertForbidden();
    }

    public function test_a_shop_user_calling_the_billing_component_directly_is_refused(): void
    {
        $shop = $this->shop();
        $invoice = $this->record($shop);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));

        try {
            Livewire::test('admin.shops.billing', ['shopId' => $shop->id])->call('markPaid', $invoice->id);
        } catch (\Throwable) {
            // refused before any action runs
        }

        $this->assertSame('unpaid', $invoice->fresh()->status->value);
    }

    public function test_a_super_admin_can_download_the_pdf_for_a_record_of_that_shop(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $invoice = $this->record($shop);

        $this->get(route('admin.shops.billing.invoices.pdf', ['shop' => $shop->id, 'invoice' => $invoice->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_record_id_tampered_through_another_shops_pdf_url_is_not_found(): void
    {
        $this->actingAs($this->superAdmin());
        $shopA = $this->shop(['name' => 'A']);
        $shopB = $this->shop(['name' => 'B']);
        $invoiceOfB = $this->record($shopB);

        $this->get(route('admin.shops.billing.invoices.pdf', ['shop' => $shopA->id, 'invoice' => $invoiceOfB->id]))
            ->assertNotFound();
    }

    public function test_a_record_id_tampered_through_another_shops_billing_card_cannot_be_changed(): void
    {
        $this->actingAs($this->superAdmin());
        $shopA = $this->shop(['name' => 'A']);
        $shopB = $this->shop(['name' => 'B']);
        $invoiceOfB = $this->record($shopB);

        try {
            Livewire::test('admin.shops.billing', ['shopId' => $shopA->id])->call('markPaid', $invoiceOfB->id);
        } catch (ModelNotFoundException) {
            // scoped lookup rejects the foreign id
        }

        $this->assertSame('unpaid', $invoiceOfB->fresh()->status->value);
    }

    public function test_the_billing_card_is_inside_the_shop_detail_components_own_boundary(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $this->record($shop);

        $html = $this->get(route('admin.shops.show', $shop))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);
        $billingOffset = null;
        $pageOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);
            $name = $snapshot['memo']['name'] ?? null;
            if ($name === 'admin.shops.billing') {
                $billingOffset = $offset;
            }
            if ($name === 'admin.shops.show') {
                $pageOffset = $offset;
            }
        }

        $this->assertNotNull($pageOffset);
        $this->assertNotNull($billingOffset);
        $this->assertGreaterThan($pageOffset, $billingOffset);

        foreach (['wire:click="openCreate"', 'wire:click="openEdit('] as $needle) {
            $offset = strpos($html, $needle);
            $this->assertNotFalse($offset, "{$needle} missing");
            $this->assertGreaterThan($billingOffset, $offset, "{$needle} sits outside the billing component boundary.");
        }
    }

    public function test_the_billing_modals_are_inside_the_billing_components_own_boundary(): void
    {
        $this->actingAs($this->superAdmin());
        $shop = $this->shop();
        $this->record($shop);

        $html = $this->get(route('admin.shops.show', $shop))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);
        $billingOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);
            if (($snapshot['memo']['name'] ?? null) === 'admin.shops.billing') {
                $billingOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($billingOffset);
        $offset = strpos($html, 'wire:submit="cancelInvoice"');
        $this->assertNotFalse($offset);
        $this->assertGreaterThan($billingOffset, $offset);
    }
}
