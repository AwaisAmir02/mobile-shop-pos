<?php

namespace Tests\Feature;

use App\Enums\BusinessCategory;
use App\Mail\VerificationCodeMail;
use App\Models\MainCategory;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\User;
use App\Services\BusinessCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected const MOBILE_ROUTES = ['balance-loads.index', 'wallet-loads.index', 'sim-sales.index', 'bills.index', 'nadra-verifications.index', 'repairs.index'];

    protected function signUp(string $category): User
    {
        Mail::fake();
        $component = Livewire::test('signup.form', ['plan' => (string) Plan::where('name', 'Growth')->value('id')]);
        $this->travel(5)->seconds();

        $component->set('shopName', 'Madina Store')->set('businessCategory', $category)->set('ownerName', 'Usman')
            ->set('phone', '0300 1234567')->set('email', 'usman@example.pk')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')->assertHasNoErrors()
            ->set('code', Mail::sent(VerificationCodeMail::class)->last()->code)->call('verify')->assertHasNoErrors();

        return User::where('email', 'usman@example.pk')->sole();
    }

    protected function categoryNames(Shop $shop): array
    {
        return MainCategory::withoutGlobalScope(ShopScope::class)->where('shop_id', $shop->id)->orderBy('id')->pluck('name')->all();
    }

    public function test_a_kiryana_signup_hides_mobile_modules_and_gets_starter_categories(): void
    {
        $owner = $this->signUp('kiryana');
        $shop = $owner->shop;

        $this->assertSame(BusinessCategory::Kiryana, $shop->business_category);
        $this->assertEqualsCanonicalizing(array_keys(BusinessCategories::modules()), BusinessCategories::hiddenModules($shop));
        $this->assertSame(['Mobile Phone', 'Accessory', 'Grocery', 'Household'], $this->categoryNames($shop));
        $this->assertTrue($shop->isScreenHidden('settings-percentage'));

        $this->actingAs($owner->fresh());
        foreach (self::MOBILE_ROUTES as $route) {
            $this->get(route($route))->assertForbidden();
        }

        $this->get(route('dashboard'))->assertOk()->assertDontSee('Balance Loaded')->assertDontSee('NADRA Verifications')->assertSee('Sales Revenue')->assertSee('Expenses');
        $this->get(route('settings.index'))->assertSee("setTab('modules')", false)->assertDontSee("setTab('percentage')", false)->assertDontSee("setTab('networks')", false);
    }

    public function test_a_mobile_shop_signup_keeps_everything_visible(): void
    {
        $owner = $this->signUp('mobile_electronics');

        $this->assertSame([], BusinessCategories::hiddenModules($owner->shop));
        $this->assertNull($owner->shop->hidden_screens);
        $this->assertSame([], array_diff($this->categoryNames($owner->shop), ['Mobile Phone', 'Accessory']));

        $this->actingAs($owner->fresh())->get(route('repairs.index'))->assertOk();
        $this->get(route('dashboard'))->assertSee('Balance Loaded')->assertSee('Repairs Revenue');
    }

    public function test_the_category_is_required_at_signup(): void
    {
        Mail::fake();
        $component = Livewire::test('signup.form');
        $this->travel(5)->seconds();

        $component->set('shopName', 'Madina Store')->set('ownerName', 'Usman')->set('phone', '0300 1234567')
            ->set('email', 'usman@example.pk')->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')->assertHasErrors(['businessCategory']);

        $component->set('businessCategory', 'spaceship')->call('submit')->assertHasErrors(['businessCategory']);
        Mail::assertNothingSent();
    }

    public function test_the_owner_can_turn_a_hidden_module_back_on_and_off_again(): void
    {
        $owner = $this->signUp('bakery');
        $this->actingAs($owner->fresh());

        Livewire::test('settings.modules')->assertSee('Bakery & sweets')->call('toggle', 'repairs');
        $this->get(route('repairs.index'))->assertOk();
        $this->get(route('dashboard'))->assertSee('Repairs Revenue')->assertDontSee('Balance Loaded');

        Livewire::test('settings.modules')->call('toggle', 'sim-sales');
        $this->assertFalse($owner->shop->fresh()->isScreenHidden('settings-percentage'));

        Livewire::test('settings.modules')->call('toggle', 'repairs');
        $this->get(route('repairs.index'))->assertForbidden();
    }

    public function test_an_admin_hard_disable_cannot_be_undone_by_the_owner(): void
    {
        $owner = $this->signUp('kiryana');
        $shop = $owner->shop;
        $shop->update(['disabled_screens' => ['repairs', 'repairs-edit-cancel']]);
        $this->actingAs($owner->fresh());

        Livewire::test('settings.modules')->assertSee('Turned off for your shop by HisabOKitab');
        Livewire::test('settings.modules')->call('toggle', 'repairs');

        $this->assertFalse($shop->fresh()->isScreenHidden('repairs'));
        $this->get(route('repairs.index'))->assertForbidden();
    }

    public function test_only_the_owner_manages_modules(): void
    {
        $owner = $this->signUp('kiryana');
        $role = Role::create(['shop_id' => $owner->shop_id, 'name' => 'Manager', 'permissions' => ['settings', 'repairs', 'dashboard']]);
        $staff = User::factory()->staff()->create(['shop_id' => $owner->shop_id, 'role_id' => $role->id]);

        Livewire::actingAs($staff)->test('settings.modules')->assertForbidden();
        $this->actingAs($staff)->get(route('repairs.index'))->assertForbidden();
    }

    public function test_shops_without_a_category_are_untouched(): void
    {
        $shop = Shop::create(['name' => 'Old Shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['dashboard', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        app(BusinessCategories::class)->applyDefaults($shop);
        $this->assertNull($shop->fresh()->hidden_screens);

        foreach (self::MOBILE_ROUTES as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }

        $this->actingAs($staff)->get(route('dashboard'))->assertSee('Balance Loaded')->assertSee('NADRA Verifications');
    }

    public function test_the_super_admin_sets_a_category_and_applies_its_defaults_explicitly(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $this->actingAs($admin);

        Livewire::test('admin.shops.create')
            ->set('shopName', 'Style Hub')->set('businessCategory', 'clothing')
            ->set('userName', 'Owner')->set('userEmail', 'owner@stylehub.pk')
            ->set('password', 'Password123!')->set('password_confirmation', 'Password123!')
            ->set('planType', 'monthly')->set('subscriptionStartDate', now()->toDateString())
            ->call('save')->assertHasNoErrors();

        $shop = Shop::where('name', 'Style Hub')->sole();
        $this->assertSame(BusinessCategory::Clothing, $shop->business_category);
        $this->assertContains('Kids', $this->categoryNames($shop));
        $this->assertNotEmpty(BusinessCategories::hiddenModules($shop));

        Livewire::test('admin.shops.show', ['shop' => $shop])->set('businessCategory', 'mobile_electronics')->call('saveInfo')->assertHasNoErrors();
        $shop->refresh();
        $this->assertSame(BusinessCategory::MobileElectronics, $shop->business_category);
        $this->assertNotEmpty(BusinessCategories::hiddenModules($shop));

        Livewire::test('admin.shops.show', ['shop' => $shop])->call('applyCategoryDefaults');
        $this->assertSame([], BusinessCategories::hiddenModules($shop->fresh()));

        $shop->update(['business_category' => 'clothing']);
        Livewire::test('admin.shops.show', ['shop' => $shop->fresh()])->call('applyCategoryDefaults')->call('applyCategoryDefaults');
        $this->assertSame(1, collect($this->categoryNames($shop))->filter(fn ($name) => $name === 'Kids')->count());
    }

    public function test_the_google_completion_page_asks_for_the_category(): void
    {
        session([\App\Http\Controllers\Auth\SocialLoginController::PENDING_KEY => ['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'sana@example.pk', 'name' => 'Sana']]);

        Livewire::test('signup.complete')->set('shopName', 'Sweet Bites')->set('phone', '0321 5556667')->call('submit')->assertHasErrors(['businessCategory']);

        Livewire::test('signup.complete')->set('shopName', 'Sweet Bites')->set('businessCategory', 'bakery')->set('phone', '0321 5556667')->call('submit')->assertHasNoErrors();

        $shop = Shop::where('name', 'Sweet Bites')->sole();
        $this->assertSame(BusinessCategory::Bakery, $shop->business_category);
        $this->assertContains('Sweets', $this->categoryNames($shop));
        $this->assertTrue($shop->isScreenHidden('repairs'));
    }
}
