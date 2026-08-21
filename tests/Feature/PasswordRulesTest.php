<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_user_must_provide_current_password_to_change_their_own(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id, 'password' => Hash::make('old-password')]);

        $this->actingAs($user);

        Livewire::test('profile.update-password-form')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('updatePassword')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_super_admin_must_provide_current_password_to_change_their_own(): void
    {
        $admin = User::factory()->create([
            'shop_id' => null,
            'is_super_admin' => true,
            'password' => Hash::make('old-admin-password'),
        ]);

        $this->actingAs($admin);

        Livewire::test('profile.update-password-form')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('updatePassword')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('old-admin-password', $admin->fresh()->password));
    }

    public function test_super_admin_can_reset_a_shops_password_without_the_old_one(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $shopUser = User::factory()->create(['shop_id' => $shop->id, 'password' => Hash::make('forgotten-password')]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->set('newPassword', 'brand-new-password')
            ->set('newPassword_confirmation', 'brand-new-password')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', $shopUser->fresh()->password));

        // The reset user can now log in with the new password.
        Livewire::test('pages.auth.login')
            ->set('form.email', $shopUser->email)
            ->set('form.password', 'brand-new-password')
            ->call('login')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($shopUser);
    }

    public function test_a_shop_user_cannot_reach_the_admin_password_reset_route(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        $this->get(route('admin.shops.show', $shop))->assertForbidden();
    }

    public function test_a_shop_user_cannot_reach_any_admin_route_by_guessing_urls(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $otherShop = Shop::create(['name' => 'Shop B']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/shops/create')->assertForbidden();
        $this->get('/admin/shops/'.$otherShop->id)->assertForbidden();
    }
}
