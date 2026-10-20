<?php

namespace Tests\Feature;

use App\Actions\UpdateRepair;
use App\Models\Repair;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\RepairReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepairChargeSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_repair_stores_each_charge_and_sums_them_into_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('description', 'Screen replacement')
            ->set('serviceCharges', '800')
            ->set('productsCharges', '1200')
            ->set('discount', '0')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertEquals(800, $repair->service_charges);
        $this->assertEquals(1200, $repair->products_charges);
        $this->assertEquals(2000, $repair->amount);
        $this->assertEquals(2000, $repair->total);
    }

    public function test_products_charges_defaults_to_zero_for_a_pure_labor_repair(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('description', 'Software reinstall')
            ->set('serviceCharges', '500')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertEquals(500, $repair->service_charges);
        $this->assertEquals(0, $repair->products_charges);
        $this->assertEquals(500, $repair->amount);
    }

    public function test_discount_still_applies_to_the_combined_total(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('description', 'Battery swap')
            ->set('serviceCharges', '300')
            ->set('productsCharges', '700')
            ->set('discount', '100')
            ->assertViewHas('totalCollected', 900.0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(900, Repair::firstOrFail()->total);
    }

    public function test_the_receipt_pdf_still_shows_only_the_combined_amount_with_no_service_products_breakdown(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $repair = Repair::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile',
            'description' => 'Screen replacement', 'amount' => 2000, 'service_charges' => 800,
            'products_charges' => 1200, 'discount' => 0, 'total' => 2000,
            'payment_status' => 'paid', 'amount_paid' => 2000,
        ]);

        $html = view('pdf.repair-receipt', ['repair' => $repair])->render();

        $this->assertStringContainsString('Rs 2,000.00', $html);
        $this->assertStringNotContainsString('Service Charges', $html);
        $this->assertStringNotContainsString('Products Charges', $html);
    }

    public function test_a_historical_repair_with_no_stored_split_shows_a_dash_in_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Legacy repair', 'amount' => 1500, 'discount' => 0, 'total' => 1500, 'payment_status' => 'paid', 'amount_paid' => 1500]);
        $this->actingAs($owner);

        $this->get(route('repairs.history'))->assertSee('Legacy repair')->assertSeeInOrder(['Legacy repair', '—', '—']);
    }

    public function test_editing_a_historical_repair_with_no_stored_split_prefills_service_charges_from_the_old_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $repair = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Legacy repair', 'amount' => 1500, 'discount' => 0, 'total' => 1500, 'payment_status' => 'paid', 'amount_paid' => 1500]);
        $this->actingAs($owner);

        Livewire::test('repairs.history')
            ->call('openEdit', $repair->id)
            ->assertSet('editServiceCharges', '1500.00')
            ->assertSet('editProductsCharges', '0')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $repair->refresh();
        $this->assertEquals(1500, $repair->service_charges);
        $this->assertEquals(0, $repair->products_charges);
        $this->assertEquals(1500, $repair->amount);
    }

    public function test_updating_the_charge_split_recomputes_amount_and_total_via_the_update_action(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $customer = \App\Models\Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $repair = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'category' => 'mobile', 'description' => 'x', 'amount' => 1000, 'service_charges' => 1000, 'products_charges' => 0, 'discount' => 0, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);

        UpdateRepair::handle($repair, [
            'category' => 'mobile',
            'sub_category' => null,
            'description' => 'x',
            'service_charges' => 600,
            'products_charges' => 500,
            'discount' => 0,
            'customer_id' => $customer->id,
        ], $owner->id);

        $repair->refresh();
        $this->assertEquals(600, $repair->service_charges);
        $this->assertEquals(500, $repair->products_charges);
        $this->assertEquals(1100, $repair->amount);
        $this->assertEquals(1100, $repair->total);
    }

    public function test_payment_status_and_udhaar_linkage_are_unaffected_by_the_charge_split(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = \App\Models\Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);
        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('customerId', (string) $customer->id)
            ->set('description', 'Screen replacement')
            ->set('serviceCharges', '800')
            ->set('productsCharges', '1200')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '500')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertSame('partial', $repair->payment_status->value);
        $this->assertEquals(500, $repair->amount_paid);
        $this->assertEquals(1500, $repair->amountOwed());
        $this->assertEqualsWithDelta(1500.0, (new \App\ReportQueries\UdhaarReport)->totalDue(), 0.01);
    }

    public function test_report_aggregates_total_service_and_products_charges_excluding_historical_nulls_and_cancelled(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);

        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'A', 'amount' => 1000, 'service_charges' => 400, 'products_charges' => 600, 'discount' => 0, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'B', 'amount' => 500, 'service_charges' => 500, 'products_charges' => 0, 'discount' => 0, 'total' => 500, 'payment_status' => 'paid', 'amount_paid' => 500]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Legacy, no split', 'amount' => 2000, 'discount' => 0, 'total' => 2000, 'payment_status' => 'paid', 'amount_paid' => 2000]);
        $cancelled = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Cancelled', 'amount' => 9999, 'service_charges' => 9999, 'products_charges' => 0, 'discount' => 0, 'total' => 9999, 'payment_status' => 'paid', 'amount_paid' => 9999]);
        $cancelled->update(['status' => 'cancelled']);

        $report = new RepairReport;

        $this->assertEqualsWithDelta(900.0, $report->totalServiceCharges(), 0.01);
        $this->assertEqualsWithDelta(600.0, $report->totalProductsCharges(), 0.01);
    }

    public function test_history_screen_summary_cards_show_the_service_and_products_charge_totals(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'A', 'amount' => 1000, 'service_charges' => 400, 'products_charges' => 600, 'discount' => 0, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000]);
        $this->actingAs($owner);

        Livewire::test('repairs.history')
            ->assertViewHas('totalServiceCharges', 400.0)
            ->assertViewHas('totalProductsCharges', 600.0)
            ->assertSee('Total Service Charges')
            ->assertSee('Total Products Charges');
    }
}
