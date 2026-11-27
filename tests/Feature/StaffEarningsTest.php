<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\Payroll;
use App\Actions\SaleReturns;
use App\Enums\CommissionKind;
use App\Enums\CommissionTarget;
use App\Models\Membership;
use App\Models\Payslip;
use App\Models\StaffCommissionRule;
use App\Support\StaffEarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Commission from service lines, and how Payroll fixes and adjusts it. */
class StaffEarningsTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('salon', ['payroll_enabled' => true]);
    }

    protected function rule(CommissionTarget $target, CommissionKind $kind, float $value, ?int $employeeId = null, ?int $targetId = null): StaffCommissionRule
    {
        return StaffCommissionRule::create(['employee_id' => $employeeId, 'target' => $target, 'target_id' => $targetId, 'kind' => $kind, 'value' => $value]);
    }

    protected function month(int $employeeId): float
    {
        return (new StaffEarnings)->forMonth($this->shop->id, $employeeId, '2026-11-01');
    }

    public function test_the_most_specific_rule_wins_and_a_personal_rule_beats_a_shop_wide_one(): void
    {
        $this->rule(CommissionTarget::AllServices, CommissionKind::Percent, 10);
        $this->rule(CommissionTarget::ServiceCategory, CommissionKind::Percent, 20, targetId: $this->hair->id);
        $this->rule(CommissionTarget::AllServices, CommissionKind::Percent, 50, $this->sara->id);

        $this->bill([$this->serviceLine($this->haircut, $this->ali), $this->serviceLine($this->facial, $this->ali), $this->serviceLine($this->haircut, $this->sara)], paid: 4100);

        $this->assertSame(160.0 + 250.0, $this->month($this->ali->id));
        $this->assertSame(400.0, $this->month($this->sara->id));
    }

    public function test_the_base_is_after_line_and_bill_discounts_and_returns(): void
    {
        $this->rule(CommissionTarget::AllServices, CommissionKind::Percent, 10);
        $sale = $this->bill([$this->serviceLine($this->facial, $this->ali, ['discount' => 500])], [[$this->shampoo, 1]], paid: 3000, invoiceDiscount: 200);

        // Facial 2000 of 3200 carries 2000/3200 of the 200 bill discount: 125. Base 1875.
        $this->assertSame(187.5, $this->month($this->ali->id));

        $key = collect(SaleReturns::groups($sale->fresh()))->first(fn ($g) => $g['name'] === 'Facial')['key'];
        SaleReturns::create($sale->fresh(), [$key => ['quantity' => '1']], 'cash', null, null, $this->owner->id, now()->toDateString(), byOwner: true);
        $this->assertSame(0.0, $this->month($this->ali->id));
    }

    public function test_flat_rates_pay_per_one_and_a_package_session_is_worth_its_share(): void
    {
        $this->rule(CommissionTarget::Service, CommissionKind::Flat, 300, targetId: $this->facial->id);
        $this->rule(CommissionTarget::AllMemberships, CommissionKind::Percent, 5);

        $packageSale = $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->tenSessions->id, 'performed_by' => $this->sara->id]], paid: 20000);
        $package = Membership::where('sale_id', $packageSale->id)->firstOrFail();
        $this->bill([['kind' => 'package', 'membership_id' => $package->id, 'service_item_id' => $this->facial->id, 'performed_by' => $this->ali->id]], paid: 0);

        $this->assertSame(1000.0, $this->month($this->sara->id), '5% of the 20,000 package sold');
        $this->assertSame(300.0, $this->month($this->ali->id), 'Flat per facial, from the package too');
    }

    public function test_payroll_fixes_the_commission_and_carries_later_changes_forward(): void
    {
        $this->rule(CommissionTarget::AllServices, CommissionKind::Percent, 10);
        $sale = $this->bill([$this->serviceLine($this->facial, $this->ali)], paid: 2500);

        Payroll::generate($this->shop->id, '2026-11', null, $this->owner->id);
        $slip = Payslip::where('employee_id', $this->ali->id)->firstOrFail();
        $line = $slip->lines()->where('label', 'like', 'Commission%')->firstOrFail();
        $this->assertSame('250.00', (string) $line->amount);
        $this->assertSame(30250.0, (float) $slip->fresh()->grossEarnings());
        Payroll::pay($slip->fresh(), '2026-12-01', null, $this->owner->id);

        // After payday the November bill is cancelled: December's commission gives the 250 back.
        $this->travelTo(now()->addMonth());
        CancelSale::handle($sale->fresh(), $this->owner->id, 'x');
        $this->bill([$this->serviceLine($this->facial, $this->ali), $this->serviceLine($this->facial, $this->ali)], paid: 5000);

        Payroll::generate($this->shop->id, '2026-12', null, $this->owner->id);
        $december = Payslip::where('employee_id', $this->ali->id)->whereDate('period', '2026-12-01')->firstOrFail();
        $line = $december->lines()->where('label', 'like', 'Commission%')->firstOrFail();
        $this->assertSame('250.00', (string) $line->amount, '500 for December less the 250 paid for the cancelled November bill');
        $this->assertStringContainsString('Rs 250.00 from earlier months', $line->label);
    }

    public function test_cancelling_a_payslip_frees_the_month(): void
    {
        $this->rule(CommissionTarget::AllServices, CommissionKind::Percent, 10);
        $this->bill([$this->serviceLine($this->facial, $this->ali)], paid: 2500);
        Payroll::generate($this->shop->id, '2026-11', null, $this->owner->id);
        $slip = Payslip::where('employee_id', $this->ali->id)->firstOrFail();

        $this->assertSame(1, \App\Models\StaffEarningLock::where('employee_id', $this->ali->id)->count());
        Payroll::cancel($slip, $this->owner->id, 'redo');
        $this->assertSame(0, \App\Models\StaffEarningLock::where('employee_id', $this->ali->id)->count());
    }

    public function test_shops_without_commission_get_no_commission_line(): void
    {
        $retail = \App\Models\Shop::create(['name' => 'K', 'business_category' => 'kiryana', 'payroll_enabled' => true]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $employee = \App\Models\Employee::create(['shop_id' => $retail->id, 'name' => 'Helper', 'rate' => 20000, 'joined_on' => '2026-01-01']);

        Payroll::generate($retail->id, '2026-11', null, null);
        $this->assertSame(0, Payslip::where('employee_id', $employee->id)->firstOrFail()->lines()->count());
        $this->assertSame(0, \App\Models\StaffEarningLock::count());
    }

    public function test_the_earnings_screen_shows_totals_by_staff(): void
    {
        $this->rule(CommissionTarget::AllServices, CommissionKind::Percent, 10);
        $this->bill([$this->serviceLine($this->facial, $this->ali)], paid: 2500);

        $this->get(route('staff-earnings.index', ['month' => '2026-11']))->assertOk()->assertSee('Ali')->assertSee('Rs 250.00');
    }
}
