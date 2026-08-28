<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadShopAccountAndFeeTest extends TestCase
{
    use RefreshDatabase;

    // ── Fee / discount / total math ─────────────────────────────────

    public function test_the_total_collected_is_computed_as_amount_plus_fee_minus_discount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '50000')
            ->set('fee', '500')
            ->set('discount', '100')
            ->assertViewHas('totalCollected', 50400.0)
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertEquals(50000, $load->amount);
        $this->assertEquals(500, $load->fee);
        $this->assertEquals(100, $load->discount);
        $this->assertEquals(50400, $load->total);
    }

    public function test_the_total_collected_never_goes_negative_when_discount_exceeds_amount_plus_fee(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '100')
            ->set('fee', '10')
            ->set('discount', '9999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0, WalletLoad::firstOrFail()->total);
    }

    public function test_saving_without_a_shop_account_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('amount', '500')
            ->call('save')
            ->assertHasErrors(['shopAccountId']);

        $this->assertSame(0, WalletLoad::count());
    }

    public function test_a_tampered_shop_account_id_from_another_shop_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $accountB = ShopAccount::create(['shop_id' => $shopB->id, 'name' => 'Their Account', 'provider_type' => 'Bank']);

        $this->actingAs($ownerA);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('shopAccountId', (string) $accountB->id)
            ->set('amount', '500')
            ->call('save')
            ->assertHasErrors(['shopAccountId']);

        $this->assertSame(0, WalletLoad::count());
    }

    // ── Shop Account selection & quick-create ───────────────────────

    public function test_selecting_new_shop_account_from_the_wallet_load_screen_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', '__create__')
            ->assertSet('shopAccountId', '')
            ->assertDispatched('open-modal', name: 'quick-create-shop-account');
    }

    public function test_a_shop_account_created_inline_is_immediately_selected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('shop-accounts.quick-create')
            ->set('name', 'My Easypaisa')
            ->set('providerType', 'Easypaisa')
            ->call('save')
            ->assertHasNoErrors();

        $account = ShopAccount::where('name', 'My Easypaisa')->firstOrFail();

        Livewire::test('wallet-loads.create')
            ->call('onShopAccountCreated', $account->id)
            ->assertSet('shopAccountId', (string) $account->id);
    }

    // ── Per-account totals & tenant isolation ───────────────────────

    public function test_settings_shows_the_total_amount_sent_per_shop_account(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accountA = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account A', 'provider_type' => 'JazzCash']);
        $accountB = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account B', 'provider_type' => 'Easypaisa']);

        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'X', 'account_number' => '1', 'shop_account_id' => $accountA->id, 'amount' => 1000, 'fee' => 50, 'discount' => 0, 'total' => 1050]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Y', 'account_number' => '2', 'shop_account_id' => $accountA->id, 'amount' => 500, 'fee' => 20, 'discount' => 0, 'total' => 520]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'shop-accounts')
            ->assertViewHas('shopAccounts', function ($accounts) use ($accountA, $accountB) {
                $a = $accounts->firstWhere('id', $accountA->id);
                $b = $accounts->firstWhere('id', $accountB->id);

                return (float) $a->wallet_loads_sum_amount === 1500.0
                    && (float) ($b->wallet_loads_sum_amount ?? 0) === 0.0;
            });
    }

    public function test_shop_accounts_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        ShopAccount::create(['shop_id' => $shopA->id, 'name' => 'Account A', 'provider_type' => 'JazzCash']);
        ShopAccount::create(['shop_id' => $shopB->id, 'name' => 'Account B', 'provider_type' => 'Easypaisa']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->set('tab', 'shop-accounts')
            ->assertSee('Account A')
            ->assertDontSee('Account B');

        $this->assertSame(1, ShopAccount::count());
    }

    public function test_deleting_a_shop_account_in_use_is_blocked_and_history_stays_intact(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account A', 'provider_type' => 'JazzCash']);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'X', 'account_number' => '1', 'shop_account_id' => $account->id, 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteShopAccount', $account->id);

        $this->assertNotNull($account->fresh());
    }

    public function test_the_history_screen_can_filter_by_shop_account(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accountA = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account A', 'provider_type' => 'JazzCash']);
        $accountB = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account B', 'provider_type' => 'Easypaisa']);

        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Via A', 'account_number' => '1', 'shop_account_id' => $accountA->id, 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'Easypaisa', 'account_name' => 'Via B', 'account_number' => '2', 'shop_account_id' => $accountB->id, 'amount' => 2000, 'fee' => 0, 'discount' => 0, 'total' => 2000]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->set('shopAccountId', (string) $accountA->id)
            ->assertSee('Via A')
            ->assertDontSee('Via B');
    }

    // ── Permission gating ────────────────────────────────────────────

    public function test_a_role_without_shop_accounts_access_cannot_manage_shop_accounts(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['settings']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        Livewire::test('settings.index')
            ->set('shopAccountName', 'Hacked Account')
            ->set('shopAccountProviderType', 'Bank')
            ->call('saveShopAccount');

        $this->assertSame(0, ShopAccount::count());
    }

    public function test_a_role_with_shop_accounts_access_can_manage_shop_accounts_without_settings_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Finance', 'permissions' => ['shop-accounts']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertOk();

        Livewire::test('settings.index')
            ->set('shopAccountName', 'Finance Account')
            ->set('shopAccountProviderType', 'Bank')
            ->call('saveShopAccount')
            ->assertHasNoErrors();

        $this->assertTrue(ShopAccount::where('name', 'Finance Account')->exists());
    }

    // ── Super Admin module toggle ────────────────────────────────────

    public function test_super_admin_can_disable_the_shop_accounts_module_for_a_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);
        Livewire::test('settings.index')->set('tab', 'shop-accounts')->assertSee('Shop Accounts');

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'shop-accounts');

        $this->assertTrue($shop->fresh()->isScreenDisabled('shop-accounts'));

        $this->actingAs($owner->fresh());
        Livewire::test('settings.index')
            ->set('shopAccountName', 'Blocked Account')
            ->set('shopAccountProviderType', 'Bank')
            ->call('saveShopAccount');

        $this->assertSame(0, ShopAccount::count());
    }
}
