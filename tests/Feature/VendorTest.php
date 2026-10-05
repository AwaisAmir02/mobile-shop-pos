<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): User
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        return $owner;
    }

    public function test_a_vendor_can_be_created(): void
    {
        $this->shopOwner();

        Livewire::test('vendors.index')
            ->set('name', 'Acme Distributors')
            ->set('phone', '03001234567')
            ->set('company_name', 'Acme Pvt Ltd')
            ->set('address', 'Main Market')
            ->set('notes', 'Pays net 30')
            ->call('save');

        $this->assertDatabaseHas('vendors', [
            'name' => 'Acme Distributors',
            'phone' => '03001234567',
            'company_name' => 'Acme Pvt Ltd',
        ]);
    }

    public function test_a_vendor_can_be_edited(): void
    {
        $owner = $this->shopOwner();
        $vendor = Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Old Name']);

        Livewire::test('vendors.index')
            ->call('openEdit', $vendor->id)
            ->set('name', 'New Name')
            ->call('save');

        $this->assertSame('New Name', $vendor->fresh()->name);
    }

    public function test_vendor_names_must_be_unique_per_shop_trimmed_and_case_insensitive(): void
    {
        $owner = $this->shopOwner();
        Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Acme Distributors']);

        Livewire::test('vendors.index')
            ->set('name', '  acme distributors  ')
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertSame(1, Vendor::count());
    }

    public function test_the_same_vendor_name_is_allowed_in_a_different_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        Vendor::create(['shop_id' => $shopA->id, 'name' => 'Acme Distributors']);

        $this->actingAs(User::factory()->create(['shop_id' => $shopB->id]));

        Livewire::test('vendors.index')
            ->set('name', 'Acme Distributors')
            ->call('save');

        $this->assertDatabaseHas('vendors', ['shop_id' => $shopA->id, 'name' => 'Acme Distributors']);
        $this->assertDatabaseHas('vendors', ['shop_id' => $shopB->id, 'name' => 'Acme Distributors']);
    }

    public function test_editing_a_vendor_does_not_trip_its_own_unique_name(): void
    {
        $owner = $this->shopOwner();
        $vendor = Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Acme Distributors']);

        Livewire::test('vendors.index')
            ->call('openEdit', $vendor->id)
            ->set('phone', '03001234567')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_vendor_without_stock_entries_can_be_deleted(): void
    {
        $owner = $this->shopOwner();
        $vendor = Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Acme Distributors']);

        Livewire::test('vendors.index')->call('delete', $vendor->id);

        $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
    }

    public function test_deleting_a_vendor_with_stock_in_history_is_blocked(): void
    {
        $owner = $this->shopOwner();
        $vendor = Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Acme Distributors']);
        StockIn::create(['shop_id' => $owner->shop_id, 'vendor_id' => $vendor->id, 'product_name' => 'Cable', 'quantity' => 5, 'stock_date' => now()->toDateString(), 'payment_status' => 'paid', 'amount_paid' => 0]);

        Livewire::test('vendors.index')->call('delete', $vendor->id);

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
    }

    public function test_vendor_search_matches_name_or_phone(): void
    {
        $owner = $this->shopOwner();
        Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Acme Distributors', 'phone' => '03001234567']);
        Vendor::create(['shop_id' => $owner->shop_id, 'name' => 'Zenith Traders', 'phone' => '03009999999']);

        Livewire::test('vendors.index')
            ->set('search', 'Acme')
            ->assertSee('Acme Distributors')
            ->assertDontSee('Zenith Traders');

        Livewire::test('vendors.index')
            ->set('search', '03009999999')
            ->assertSee('Zenith Traders')
            ->assertDontSee('Acme Distributors');
    }

    public function test_a_shop_only_sees_its_own_vendors(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        Vendor::create(['shop_id' => $shopA->id, 'name' => 'Shop A Vendor']);
        Vendor::create(['shop_id' => $shopB->id, 'name' => 'Shop B Vendor']);

        $this->actingAs(User::factory()->create(['shop_id' => $shopA->id]));

        Livewire::test('vendors.index')
            ->assertSee('Shop A Vendor')
            ->assertDontSee('Shop B Vendor');
    }
}
