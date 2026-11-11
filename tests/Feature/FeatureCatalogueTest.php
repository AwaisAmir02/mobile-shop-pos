<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\ShopModule;
use App\Enums\ShopScreen;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeatureCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));
    }

    protected function plan(array $limits = [], array $flags = []): Plan
    {
        return Plan::create(['name' => 'Catalogue', 'price' => 1000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => $flags]);
    }

    public function test_features_pair_with_at_most_one_limit_and_the_rest_are_core(): void
    {
        $this->assertSame(LimitKey::WhatsAppMessagesPerMonth, FeatureKey::WhatsAppMessaging->limit());
        $this->assertSame(LimitKey::MarketingPostsPerMonth, FeatureKey::Social->limit());
        $this->assertNull(FeatureKey::Barcode->limit());
        $this->assertNull(FeatureKey::Branding->limit());

        $paired = collect(FeatureKey::cases())->map->limit()->filter();
        $this->assertSame($paired->count(), $paired->unique()->count(), 'Two features share one limit.');
        $this->assertSame([LimitKey::Products, LimitKey::StaffUsers, LimitKey::SalesPerMonth, LimitKey::Branches], LimitKey::core());
        $this->assertSame(FeatureKey::WhatsAppMessaging, LimitKey::WhatsAppMessagesPerMonth->feature());

        $this->assertSame(1, LimitKey::Branches->minimum());
        $this->assertSame(0, LimitKey::Products->minimum());
        $this->assertSame('products_allowed', LimitKey::Products->legacyColumn());
        $this->assertNull(LimitKey::StaffUsers->legacyColumn());
        $this->assertSame([LimitKey::Branches], array_values(array_filter(LimitKey::cases(), fn (LimitKey $key) => $key->packRecurs())));
    }

    public function test_every_feature_gets_an_admin_switch_without_listing_it_by_hand(): void
    {
        $this->assertEqualsCanonicalizing(FeatureKey::cases(), [...FeatureKey::adminSwitches(), FeatureKey::Import]);

        $shop = Shop::create(['name' => 'Switches']);
        $page = Livewire::test('admin.shops.show', ['shop' => $shop]);

        // A feature with screens of its own is switched by its module's row; the rest have their own switch. Never both.
        foreach (FeatureKey::cases() as $feature) {
            $module = ShopModule::forFeature($feature);

            if ($module !== null) {
                $page->assertSeeHtml("toggleModule('{$module->value}')")->assertDontSeeHtml("toggleFeature('{$feature->value}')");
            } else {
                $page->assertSeeHtml("toggleFeature('{$feature->value}')")->assertSee($feature->adminSwitch()['label']);
            }
        }

        $page->call('toggleFeature', 'barcode')->assertDispatched('toast', message: 'Barcodes disabled for this shop.');
        $this->assertFalse($shop->fresh()->barcode_enabled);

        $page->call('toggleModule', 'services')->assertDispatched('toast', message: 'Services turned on for this shop.');
        $this->assertTrue($shop->fresh()->services_enabled);
    }

    public function test_every_screen_belongs_to_exactly_one_admin_module(): void
    {
        $owners = collect(ShopScreen::cases())->mapWithKeys(fn (ShopScreen $screen) => [
            $screen->value => collect(ShopModule::cases())->filter(fn (ShopModule $module) => in_array($screen, $module->screens(), true))->count(),
        ]);

        $this->assertSame([], $owners->reject(fn (int $count) => $count === 1)->all());
        $this->assertEqualsCanonicalizing(ShopModule::cases(), array_merge(...array_values(ShopModule::grouped())));
    }

    public function test_a_feature_module_switches_the_feature_and_its_screens_together(): void
    {
        $shop = Shop::create(['name' => 'Modules', 'disabled_screens' => ['payroll-run']]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $page = Livewire::test('admin.shops.show', ['shop' => $shop]);

        $page->call('toggleModule', 'payroll');
        $shop->refresh();
        $this->assertTrue($shop->payroll_enabled);
        $this->assertSame([], $shop->disabled_screens, 'Turning a module on clears what was left of the old per-screen switches.');
        $this->assertTrue($owner->fresh()->hasAccessTo('payroll-run'));

        $page->call('toggleModule', 'payroll');
        $this->assertFalse($shop->fresh()->payroll_enabled);
    }

    public function test_a_plain_module_switches_its_sub_permissions_with_it(): void
    {
        $shop = Shop::create(['name' => 'Modules']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $page = Livewire::test('admin.shops.show', ['shop' => $shop]);

        $page->call('toggleModule', 'bills');
        $this->assertSame(['bills'], $shop->fresh()->disabled_screens);
        foreach (['bills', 'bills-edit-cancel', 'settings-bill-config'] as $screen) {
            $this->assertFalse($owner->fresh()->hasAccessTo($screen), $screen);
        }

        $page->call('toggleModule', 'bills');
        $this->assertTrue($owner->fresh()->hasAccessTo('settings-bill-config'));
    }

    public function test_a_module_the_owner_hid_is_flagged_on_the_admin_page(): void
    {
        $shop = Shop::create(['name' => 'Modules', 'hidden_screens' => ['repairs', 'repairs-edit-cancel']]);

        Livewire::test('admin.shops.show', ['shop' => $shop])->assertSee('Hidden by the shop in Settings → Modules');
    }

    public function test_admin_shop_limit_overrides_are_validated_per_limit(): void
    {
        $shop = Shop::create(['name' => 'Overrides']);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->set('limitOverrides.branches', '0')->set('limitOverrides.products', '-1')
            ->call('saveSubscription')
            ->assertHasErrors(['limitOverrides.branches' => 'The Branches field must be at least 1.', 'limitOverrides.products'])
            ->assertHasNoErrors(['limitOverrides.staff_users']);

        $this->assertNull($shop->fresh()->limit_overrides);
    }

    public function test_the_plan_editor_shows_a_limit_toggle_only_on_features_with_a_limit(): void
    {
        $editor = Livewire::test('admin.plans')->call('openCreate')
            ->assertSee('Core limits')
            ->assertSee('Has a usage limit?');

        $html = $editor->html();
        $this->assertSame(4, substr_count($html, 'Has a usage limit?'), 'WhatsApp ordering, WhatsApp messaging, Online store and Social.');

        foreach (LimitKey::core() as $key) {
            $this->assertStringContainsString('id="limit-'.$key->value.'"', $html);
        }

        // Paired limits are hidden until their toggle is on.
        $this->assertStringNotContainsString('id="limit-whatsapp_messages_per_month"', $html);
        $editor->set('limited.whatsapp_messages_per_month', true);
        $this->assertStringContainsString('id="limit-whatsapp_messages_per_month"', $editor->html());
        $this->assertStringContainsString('messages a month', $editor->html());
    }

    public function test_the_toggle_starts_from_the_saved_limit_and_off_saves_unlimited(): void
    {
        $plan = $this->plan(['whatsapp_messages_per_month' => 50, 'marketing_posts_per_month' => null], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true]);

        $editor = Livewire::test('admin.plans')->call('openEdit', $plan->id)
            ->assertSet('limited.whatsapp_messages_per_month', true)
            ->assertSet('limited.marketing_posts_per_month', false)
            ->assertSet('limits.whatsapp_messages_per_month', '50');

        $editor->set('limited.whatsapp_messages_per_month', false)
            ->assertSet('limits.whatsapp_messages_per_month', '')
            ->call('save')->assertHasNoErrors();

        $this->assertNull($plan->fresh()->limits['whatsapp_messages_per_month']);
        $this->assertTrue($plan->fresh()->feature_flags['whatsapp_messaging'], 'The feature stays on; only its limit went.');
    }

    public function test_a_limit_toggled_on_needs_a_number_and_zero_is_allowed(): void
    {
        $plan = $this->plan();

        Livewire::test('admin.plans')->call('openEdit', $plan->id)
            ->set('limited.marketing_posts_per_month', true)
            ->call('save')
            ->assertHasErrors(['limits.marketing_posts_per_month' => 'Enter a limit, or turn off “Has a usage limit?”.']);

        Livewire::test('admin.plans')->call('openEdit', $plan->id)
            ->set('limited.marketing_posts_per_month', true)->set('limits.marketing_posts_per_month', '0')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(0, $plan->fresh()->limits['marketing_posts_per_month']);
        $this->assertFalse($plan->fresh()->feature_flags['social'], 'A limit can be set while its feature is off.');
    }
}
