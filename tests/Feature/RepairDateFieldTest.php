<?php

namespace Tests\Feature;

use App\Actions\UpdateRepair;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Repair;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\RepairReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepairDateFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setupShopAndOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Mobile', 'slug' => 'mobile', 'is_default' => true]);

        return [$shop, $owner];
    }

    public function test_repair_date_defaults_to_today_in_pakistan_timezone(): void
    {
        $this->setupShopAndOwner();

        $expectedToday = Carbon::now('Asia/Karachi')->toDateString();

        Livewire::test('repairs.create')->assertSet('repairDate', $expectedToday);
    }

    public function test_a_repair_can_be_backdated_and_stored_in_utc_properly(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();

        Livewire::test('repairs.create')
            ->set('repairDate', '2026-02-10')
            ->set('mainCategorySlug', 'mobile')
            ->set('description', 'Microphone repair')
            ->set('serviceCharges', '1000')
            ->set('productsCharges', '0')
            ->set('discount', '0')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::first();
        $this->assertNotNull($repair);

        $karachiDate = $repair->created_at->timezone('Asia/Karachi')->toDateString();
        $this->assertSame('2026-02-10', $karachiDate);
    }

    public function test_repair_receipt_displays_time_in_pakistan_timezone(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();

        // 14:00 PKT is 09:00 UTC
        $utcTime = Carbon::parse('2026-03-15 09:00:00', 'UTC');

        $repair = new Repair([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'category' => 'mobile',
            'description' => 'Battery replacement',
            'amount' => 1500,
            'service_charges' => 500,
            'products_charges' => 1000,
            'discount' => 0,
            'total' => 1500,
            'payment_status' => 'paid',
            'amount_paid' => 1500,
        ]);
        $repair->created_at = $utcTime;
        $repair->save();

        $html = view('pdf.repair-receipt', ['repair' => $repair])->render();

        // 09:00 UTC in Asia/Karachi (+5) is 02:00 PM
        $this->assertStringContainsString('15 Mar 2026, 02:00 PM', $html);
    }

    public function test_future_date_relative_to_pakistan_today_is_rejected_on_create(): void
    {
        $this->setupShopAndOwner();

        $tomorrowKarachi = Carbon::now('Asia/Karachi')->addDay()->toDateString();

        Livewire::test('repairs.create')
            ->set('repairDate', $tomorrowKarachi)
            ->set('mainCategorySlug', 'mobile')
            ->set('description', 'Test future')
            ->set('serviceCharges', '500')
            ->call('save')
            ->assertHasErrors(['repairDate']);

        $this->assertSame(0, Repair::count());
    }

    public function test_midnight_crossing_at_00_30_pkt_accepts_pkt_today_date(): void
    {
        // 2026-05-10 00:30:00 PKT = 2026-05-09 19:30:00 UTC (previous day in UTC)
        Carbon::setTestNow(Carbon::parse('2026-05-09 19:30:00', 'UTC'));

        [$shop, $owner] = $this->setupShopAndOwner();

        $expectedTodayInPkt = '2026-05-10';
        $this->assertSame($expectedTodayInPkt, Carbon::now('Asia/Karachi')->toDateString());

        Livewire::test('repairs.create')
            ->assertSet('repairDate', $expectedTodayInPkt)
            ->set('mainCategorySlug', 'mobile')
            ->set('description', 'Late night screen fix')
            ->set('serviceCharges', '1200')
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::first();
        $this->assertNotNull($repair);
        $this->assertSame('2026-05-10', $repair->created_at->timezone('Asia/Karachi')->toDateString());

        Carbon::setTestNow(); // reset
    }

    public function test_edit_modal_pre_fills_karachi_date_and_allows_editing_it(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();

        $utcTime = Carbon::parse('2026-04-10 10:00:00', 'UTC');
        $repair = new Repair([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'category' => 'mobile',
            'description' => 'Port repair',
            'amount' => 800,
            'service_charges' => 800,
            'products_charges' => 0,
            'discount' => 0,
            'total' => 800,
            'payment_status' => 'paid',
            'amount_paid' => 800,
        ]);
        $repair->created_at = $utcTime;
        $repair->save();

        Livewire::test('repairs.history')
            ->call('openEdit', $repair->id)
            ->assertSet('editRepairDate', '2026-04-10')
            ->set('editRepairDate', '2026-04-05')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('2026-04-05', $repair->fresh()->created_at->timezone('Asia/Karachi')->toDateString());
    }

    public function test_future_date_is_rejected_on_edit(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();

        $repair = Repair::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'category' => 'mobile',
            'description' => 'Test',
            'amount' => 500,
            'service_charges' => 500,
            'products_charges' => 0,
            'discount' => 0,
            'total' => 500,
            'payment_status' => 'paid',
            'amount_paid' => 500,
        ]);

        $tomorrowKarachi = Carbon::now('Asia/Karachi')->addDay()->toDateString();

        Livewire::test('repairs.history')
            ->call('openEdit', $repair->id)
            ->set('editRepairDate', $tomorrowKarachi)
            ->call('saveEdit')
            ->assertHasErrors(['editRepairDate']);
    }

    public function test_backdated_repair_is_filtered_in_repair_report_by_chosen_date(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();

        // 2026-01-15 noon in PKT
        $utcTime = Carbon::parse('2026-01-15 12:00:00', 'Asia/Karachi')->utc();

        $repair = new Repair([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'category' => 'mobile',
            'description' => 'Backdated repair',
            'amount' => 700,
            'service_charges' => 700,
            'products_charges' => 0,
            'discount' => 0,
            'total' => 700,
            'payment_status' => 'paid',
            'amount_paid' => 700,
        ]);
        $repair->created_at = $utcTime;
        $repair->save();

        $reportMatch = (new RepairReport('2026-01-15', '2026-01-15'))->query()->count();
        $reportMiss = (new RepairReport('2026-01-16', '2026-01-16'))->query()->count();

        $this->assertSame(1, $reportMatch);
        $this->assertSame(0, $reportMiss);
    }
}
