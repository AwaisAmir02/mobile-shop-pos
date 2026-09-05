<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NadraVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_verification_computes_the_total_as_amount_minus_discount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->set('discount', '50')
            ->assertViewHas('totalCollected', 150.0)
            ->call('save')
            ->assertHasNoErrors();

        $verification = NadraVerification::firstOrFail();
        $this->assertSame($shop->id, $verification->shop_id);
        $this->assertSame('03001234567', $verification->phone_number);
        $this->assertSame('12345-1234567-1', $verification->cnic_number);
        $this->assertEquals(200, $verification->amount);
        $this->assertEquals(50, $verification->discount);
        $this->assertEquals(150, $verification->total);
        $this->assertNull($verification->customer_id);
    }

    public function test_a_manually_entered_service_fee_is_included_in_the_total(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->set('fee', '30')
            ->set('discount', '50')
            ->assertViewHas('totalCollected', 180.0)
            ->call('save')
            ->assertHasNoErrors();

        $verification = NadraVerification::firstOrFail();
        $this->assertEquals(200, $verification->amount);
        $this->assertEquals(30, $verification->fee);
        $this->assertEquals(50, $verification->discount);
        $this->assertEquals(180, $verification->total);
    }

    public function test_the_total_never_goes_negative_when_discount_exceeds_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '100')
            ->set('discount', '9999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0, NadraVerification::firstOrFail()->total);
    }

    public function test_a_verification_can_be_linked_to_a_customer_or_left_as_walk_in(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('customerId', (string) $customer->id)
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($customer->id, NadraVerification::firstOrFail()->customer_id);
    }

    public function test_selecting_new_customer_opens_the_shared_quick_create_component(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');
    }

    public function test_a_tampered_customer_id_from_another_shop_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        $this->actingAs($ownerA);

        Livewire::test('nadra-verifications.create')
            ->set('customerId', (string) $customerB->id)
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->call('save')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, NadraVerification::count());
    }

    public function test_verifications_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        NadraVerification::create(['shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'phone_number' => 'A-Phone', 'cnic_number' => 'A-CNIC', 'amount' => 100, 'discount' => 0, 'total' => 100]);
        NadraVerification::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'phone_number' => 'B-Phone', 'cnic_number' => 'B-CNIC', 'amount' => 200, 'discount' => 0, 'total' => 200]);

        $this->actingAs($ownerA);

        Livewire::test('nadra-verifications.history')
            ->assertSee('A-Phone')
            ->assertDontSee('B-Phone');

        $this->assertSame(1, NadraVerification::count());
    }

    public function test_a_role_without_nadra_verifications_access_cannot_reach_the_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('nadra-verifications.index'))->assertForbidden();
        $this->get(route('nadra-verifications.history'))->assertForbidden();
    }

    public function test_a_role_with_nadra_verifications_access_can_reach_the_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Verification Agent', 'permissions' => ['nadra-verifications']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('nadra-verifications.index'))->assertOk();
        $this->get(route('nadra-verifications.history'))->assertOk();
    }

    public function test_super_admin_can_disable_the_nadra_verification_module_for_a_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);
        $this->get(route('nadra-verifications.index'))->assertOk();

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'nadra-verifications');

        $this->assertTrue($shop->fresh()->isScreenDisabled('nadra-verifications'));

        $this->actingAs($owner->fresh());
        $this->get(route('nadra-verifications.index'))->assertForbidden();
    }

    public function test_nadra_revenue_appears_as_its_own_line_in_reports(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();

        NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => 'X', 'cnic_number' => 'Y', 'amount' => 300, 'discount' => 0, 'total' => 300]);

        $this->actingAs($owner);

        Livewire::test('reports.index')
            ->set('day', $today)
            ->assertViewHas('totalNadraRevenue', 300.0)
            ->assertViewHas('totalNadraVerifications', 1)
            ->assertViewHas('totalRevenue', 0.0);
    }
}
