<?php

namespace Tests\Feature;

use App\Enums\BusinessCategory;
use App\Enums\BusinessGroup;
use App\Mail\VerificationCodeMail;
use App\Models\MainCategory;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\User;
use App\Services\BusinessCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessGroupPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function fill($component, string $email = 'owner@example.pk')
    {
        return $component->set('shopName', 'Test Shop')->set('ownerName', 'Owner')
            ->set('phone', '0300 1234567')->set('email', $email)
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1');
    }

    protected function signUpAs(string $group, string $category, string $email): Shop
    {
        Mail::fake();
        $component = Livewire::test('signup.form');
        $this->travel(5)->seconds();

        $this->fill($component, $email)->set('businessGroup', $group)->set('businessCategory', $category)
            ->call('submit')->assertHasNoErrors()
            ->set('code', Mail::sent(VerificationCodeMail::class)->last()->code)->call('verify')->assertHasNoErrors();
        auth()->logout();

        return User::where('email', $email)->sole()->shop;
    }

    public function test_step_one_offers_exactly_the_four_built_groups(): void
    {
        $this->assertSame(['retail', 'wholesale', 'online', 'service_trades'], array_map(fn ($g) => $g->value, BusinessGroup::cases()));

        $html = Livewire::test('signup.form')->html();

        $this->assertSame(4, substr_count($html, 'data-business-group='));
        $this->assertStringContainsString('Services &amp; appointments', $html);
        $this->assertStringContainsString('What kind of business are you?', $html);
        $this->assertStringContainsString('Shops (Retail)', $html);
        $this->assertStringContainsString('Wholesale / Distribution', $html);
        $this->assertStringContainsString('Online seller', $html);
        $this->assertSame(0, substr_count($html, 'data-business-type='), 'Sub-types appear only after a group is picked.');

        foreach (['Bakery', 'Food', 'Education', 'Services', 'Company'] as $hidden) {
            $this->assertStringNotContainsString('data-business-group="'.strtolower($hidden).'"', $html);
        }
        $this->assertStringNotContainsString('Bakery &amp; sweets', $html);
    }

    public function test_step_two_lists_only_the_sub_types_of_the_chosen_group(): void
    {
        $expected = [
            'retail' => ['mobile_electronics', 'kiryana', 'pharmacy', 'clothing', 'other'],
            'wholesale' => ['wholesaler', 'trader', 'distributor'],
            'online' => ['social_seller', 'daraz_seller', 'online_brand'],
        ];

        $component = Livewire::test('signup.form');

        foreach ($expected as $group => $values) {
            preg_match_all('/data-business-type="([a-z_]+)"/', $component->set('businessGroup', $group)->html(), $m);
            $this->assertSame($values, $m[1], $group);
        }
    }

    public function test_changing_the_group_clears_a_sub_type_from_another_group(): void
    {
        $component = Livewire::test('signup.form')->set('businessGroup', 'retail')->set('businessCategory', 'kiryana');

        $component->set('businessGroup', 'retail')->assertSet('businessCategory', 'kiryana');
        $component->set('businessGroup', 'online')->assertSet('businessCategory', '');
        $component->set('businessGroup', 'nonsense')->assertSet('businessGroup', '');
    }

    public function test_choosing_a_sub_type_selects_its_group(): void
    {
        Livewire::test('signup.form')->set('businessCategory', 'distributor')->assertSet('businessGroup', 'wholesale');
    }

    public function test_a_group_alone_is_not_enough(): void
    {
        Mail::fake();
        $component = Livewire::test('signup.form');
        $this->travel(5)->seconds();

        $this->fill($component)->call('submit')->assertHasErrors(['businessCategory'])->assertSee('Choose what kind of business you are.');
        $component->set('businessGroup', 'wholesale')->call('submit')->assertHasErrors(['businessCategory'])->assertSee('Choose the type of business that fits you best.');

        Mail::assertNothingSent();
    }

    public function test_sub_types_signup_no_longer_offers_are_refused(): void
    {
        Mail::fake();
        $component = Livewire::test('signup.form');
        $this->travel(5)->seconds();

        foreach (['bakery', 'online_seller'] as $legacy) {
            $this->fill($component)->set('businessCategory', $legacy)->call('submit')->assertHasErrors(['businessCategory' => 'in']);
        }

        session([\App\Http\Controllers\Auth\SocialLoginController::PENDING_KEY => ['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'sana@example.pk', 'name' => 'Sana']]);
        Livewire::test('signup.complete')->set('shopName', 'Sweet Bites')->set('businessCategory', 'bakery')->set('phone', '0321 5556667')
            ->call('submit')->assertHasErrors(['businessCategory' => 'in']);

        Mail::assertNothingSent();
    }

    public function test_every_offered_sub_type_keeps_its_existing_setup(): void
    {
        config(['billing.signup.per_hour' => 100, 'billing.signup.per_day' => 100, 'billing.signup.attempts_per_10_minutes' => 100, 'verification.max_sends_per_ip_per_hour' => 100]);
        $n = 0;
        foreach (BusinessGroup::cases() as $group) {
            foreach ($group->signupCases() as $category) {
                $shop = $this->signUpAs($group->value, $category->value, 'owner'.(++$n).'@example.pk');
                $names = MainCategory::withoutGlobalScope(ShopScope::class)->where('shop_id', $shop->id)->orderBy('id')->pluck('name')->all();

                $this->assertSame($category, $shop->business_category);
                $this->assertSame($category === BusinessCategory::MobileElectronics ? [] : array_keys(BusinessCategories::modules()), BusinessCategories::hiddenModules($shop), $category->value);
                $this->assertSame($category->starterCategories(), array_values(array_diff($names, ['Mobile Phone', 'Accessory'])), $category->value);
            }
        }

        $this->assertSame(16, $n);
    }

    public function test_the_google_completion_page_uses_the_same_picker(): void
    {
        session([\App\Http\Controllers\Auth\SocialLoginController::PENDING_KEY => ['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'sana@example.pk', 'name' => 'Sana']]);

        $component = Livewire::test('signup.complete');
        $this->assertSame(4, substr_count($component->html(), 'data-business-group='));

        $component->set('shopName', 'Sana Traders')->set('businessGroup', 'wholesale')->set('businessCategory', 'trader')->set('phone', '0321 5556667')
            ->call('submit')->assertHasNoErrors();

        $this->assertSame(BusinessCategory::Trader, Shop::where('name', 'Sana Traders')->sole()->business_category);
    }

    public function test_shops_on_a_retired_sub_type_still_work(): void
    {
        $shop = Shop::create(['name' => 'Old Bakery', 'business_category' => 'bakery']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->assertNull(BusinessCategory::Bakery->group());
        $this->actingAs($owner);
        Livewire::test('settings.modules')->assertSee('Bakery & sweets');
    }

    public function test_both_signup_password_fields_have_a_show_hide_button(): void
    {
        $html = Livewire::test('signup.form')->html();

        $this->assertSame(2, substr_count($html, 'data-password-toggle'));
        $this->assertMatchesRegularExpression('/id="password"[^>]*type="password"|type="password"[^>]*id="password"/', $html);
    }
}
