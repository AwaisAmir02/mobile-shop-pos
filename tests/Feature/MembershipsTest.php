<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\Memberships;
use App\Enums\MemberStanding;
use App\Models\Membership;
use App\ReportQueries\MembershipRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Memberships (by time) and packages (by sessions), sold on the bill. */
class MembershipsTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('gym');
    }

    protected function sell(\App\Models\MembershipPlan $plan, ?float $paid = null): Membership
    {
        $sale = $this->bill([['kind' => 'membership', 'membership_plan_id' => $plan->id, 'quantity' => 1, 'performed_by' => $this->sara->id]], paid: $paid ?? (float) $plan->price);

        return Membership::where('sale_id', $sale->id)->firstOrFail();
    }

    public function test_a_membership_sold_with_protein_on_one_bill_runs_a_month(): void
    {
        $sale = $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id, 'quantity' => 1]], [[$this->shampoo, 1]], paid: 6200);
        $membership = Membership::where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame('2026-11-10', $membership->starts_on->toDateString());
        $this->assertSame('2026-12-09', $membership->ends_on->toDateString());
        $this->assertSame(MemberStanding::Active, Memberships::standing($this->client)['standing']);
        $this->assertSame(0.0, $sale->fresh()->amountDue());
    }

    public function test_a_membership_needs_a_customer(): void
    {
        $this->expectException(ValidationException::class);
        \App\Actions\CreateSale::handle([], 0, null, $this->owner->id, now()->toDateString(), 5000, paymentMethod: 'cash', services: [['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]]);
    }

    public function test_renewing_early_carries_on_from_the_current_end(): void
    {
        $this->sell($this->monthly);
        $this->travelTo(now()->addDays(25));
        $renewal = $this->sell($this->monthly);

        $this->assertSame('2026-12-10', $renewal->starts_on->toDateString());
        $this->assertSame('2027-01-09', $renewal->ends_on->toDateString());
    }

    public function test_an_unpaid_renewal_shows_as_dues_and_expiry_as_expired(): void
    {
        $this->sell($this->monthly, paid: 0);
        $this->travelTo(now()->addDays(40));

        $roster = (new MembershipRoster($this->shop->id, now('Asia/Karachi')->toDateString()))->rows();
        $this->assertSame(MemberStanding::Expired, $roster->first()['standing']);
        $this->assertSame(5000.0, $roster->first()['dues']);
    }

    public function test_freezing_moves_the_end_out_and_respects_the_limit(): void
    {
        $membership = $this->sell($this->monthly);
        Memberships::freeze($membership, '2026-11-15', '2026-11-24', 'Travel', $this->owner->id);

        $this->assertSame('2026-12-19', $membership->fresh()->ends_on->toDateString());
        $this->assertSame(MemberStanding::Frozen, Memberships::standing($this->client, '2026-11-20')['standing']);

        try {
            Memberships::freeze($membership->fresh(), '2026-11-26', '2026-12-02', null, $this->owner->id);
            $this->fail('Over the freeze limit.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('15 frozen days', collect($e->errors())->flatten()->first());
        }

        Memberships::unfreeze($membership->freezes()->first());
        $this->assertSame('2026-12-09', $membership->fresh()->ends_on->toDateString());
    }

    public function test_package_sessions_are_used_on_later_bills_at_no_charge(): void
    {
        $package = $this->sell($this->tenSessions);
        $session = $this->bill([['kind' => 'package', 'membership_id' => $package->id, 'service_item_id' => $this->facial->id, 'performed_by' => $this->ali->id]], paid: 0);

        $this->assertSame('0.00', (string) $session->total);
        $this->assertSame(9, $package->fresh()->sessionsLeft());

        $this->expectException(ValidationException::class);
        $this->bill([['kind' => 'package', 'membership_id' => $package->id, 'service_item_id' => $this->haircut->id]], paid: 0);
    }

    public function test_cancelling_the_sale_cancels_its_membership_unless_sessions_were_used(): void
    {
        $package = $this->sell($this->tenSessions);
        $session = $this->bill([['kind' => 'package', 'membership_id' => $package->id, 'service_item_id' => $this->facial->id]], paid: 0);

        try {
            CancelSale::handle($package->sale, $this->owner->id, 'x');
            $this->fail('Sessions were used.');
        } catch (ValidationException) {
        }

        CancelSale::handle($session, $this->owner->id, 'x');
        $this->assertSame(10, $package->fresh()->sessionsLeft());
        CancelSale::handle($package->sale()->first(), $this->owner->id, 'x');
        $this->assertTrue($package->fresh()->isCancelled());
    }

    public function test_the_register_renews_a_membership(): void
    {
        $membership = $this->sell($this->monthly);

        $this->get(route('sales.index', ['renew' => $membership->id]))->assertOk()->assertSee('Monthly');
        \Livewire\Livewire::withQueryParams(['renew' => $membership->id])->test('sales.create')->call('completeSale')->assertHasNoErrors();

        $this->assertSame(2, Membership::count());
    }

    public function test_the_members_screen_lists_and_filters(): void
    {
        $this->sell($this->monthly, paid: 1000);

        $this->get(route('memberships.index'))->assertOk()->assertSee('Hina')->assertSee('Rs 4,000.00');
        $this->get(route('memberships.index', ['filter' => 'expired']))->assertOk()->assertDontSee('Hina');
    }
}
