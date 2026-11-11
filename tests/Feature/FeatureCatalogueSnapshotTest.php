<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Billing output recorded before the feature catalogue refactor (enum-driven
 * settings, generic admin shop page, Plan editor feature rows) and asserted
 * identical after it: meters(), features(), what the admin shop page shows
 * and saves, and what the Plan editor saves for every plan.
 *
 * A deliberate change to any of these (a new FeatureKey or LimitKey case,
 * new wording) changes the snapshot. Re-record it with
 * UPDATE_FEATURE_SNAPSHOT=1 and review the fixture diff before committing.
 */
class FeatureCatalogueSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected const FIXTURE = 'tests/Fixtures/feature-catalogue-snapshot.json';

    public function test_billing_output_matches_the_recorded_snapshot(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'Asia/Karachi'));
        $this->seedScenario();

        $snapshot = $this->snapshot();
        $path = base_path(self::FIXTURE);

        if (getenv('UPDATE_FEATURE_SNAPSHOT') || ! file_exists($path)) {
            file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->fail('Snapshot written to '.self::FIXTURE.'; run the test again.');
        }

        $this->assertSame(json_decode(file_get_contents($path), true), json_decode(json_encode($snapshot), true));
    }

    protected function seedScenario(): void
    {
        $full = $this->plan('Full', [
            'products' => 10, 'staff_users' => 2, 'sales_per_month' => 100,
            'whatsapp_messages_per_month' => 50, 'marketing_posts_per_month' => 5, 'branches' => 2,
        ], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true, 'social' => false, 'ads' => false, 'import' => true, 'barcode' => true, 'branding' => false, 'services' => true]);
        $partial = $this->plan('Partial', ['products' => 3, 'branches' => null, 'marketing_posts_per_month' => 0], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true, 'barcode' => false]);
        $empty = $this->plan('Empty', [], []);

        $this->shop('Ungoverned', null, ['whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true, 'products_allowed' => 40]);
        $this->shop('On Starter', Plan::where('name', 'Starter')->sole());

        $onFull = $this->shop('On Full', $full);
        foreach (range(1, 9) as $i) {
            Product::create(['shop_id' => $onFull->id, 'type' => 'accessory', 'name' => "Item {$i}", 'price' => 100, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);
        }
        User::factory()->staff()->create(['shop_id' => $onFull->id, 'name' => 'Staff Full', 'email' => 'staff-full@example.test']);
        Branch::withoutGlobalScopes()->create(['shop_id' => $onFull->id, 'name' => 'City']);

        $this->shop('Overridden', $partial, [
            'limit_overrides' => ['products' => 5, 'sales_per_month' => 20, 'staff_users' => 1, 'whatsapp_messages_per_month' => 0],
            'feature_overrides' => ['ads' => true, 'whatsapp_ordering' => false, 'whatsapp_messaging' => false],
            'products_allowed' => 5, 'sales_allowed' => 20,
        ]);

        $addons = $this->shop('Addons', $partial, ['services_enabled' => true]);
        $pack = Addon::create(['name' => 'Pack', 'kind' => 'limit_pack', 'limit_key' => 'products', 'quantity' => 500, 'price' => 500, 'billing' => 'one_off']);
        $social = Addon::create(['name' => 'Social unlock', 'kind' => 'feature_unlock', 'feature_keys' => ['social'], 'price' => 1500, 'billing' => 'monthly']);
        $ads = Addon::create(['name' => 'Ads unlock', 'kind' => 'feature_unlock', 'feature_keys' => ['ads'], 'price' => 1500, 'billing' => 'monthly']);
        $this->shopAddon($addons, $pack, ['limit_key' => 'products', 'quantity' => 500, 'ends_at' => null]);
        $this->shopAddon($addons, $social, ['feature_keys' => ['social'], 'ends_at' => now()->addDays(10)]);
        $this->shopAddon($addons, $ads, ['feature_keys' => ['ads'], 'ends_at' => now()->subDay()]);

        $this->shop('Legacy only', $empty, ['products_allowed' => 7, 'sales_allowed' => 30]);

        foreach (Shop::all() as $shop) {
            app(Entitlements::class)->syncFeatureFlags($shop);
        }

        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null, 'name' => 'Admin', 'email' => 'admin@example.test']));
    }

    protected function snapshot(): array
    {
        $snapshot = ['entitlements' => $this->entitlements()];

        foreach ($this->shops() as $shop) {
            $page = Livewire::test('admin.shops.show', ['shop' => $shop]);
            $snapshot['admin_page'][$shop->name] = ['text' => $this->pageText($page->html()), 'attributes' => $this->pageAttributes($page->html()), 'form' => $this->limitForm($page)];
            $page->call('saveSubscription');
            $snapshot['admin_save'][$shop->name] = ['errors' => $page->errors()->keys(), 'shop' => $this->shopState($shop->fresh())];
        }

        $snapshot['after_admin_save'] = $this->entitlements();

        $snapshot['admin_validation'] = collect(LimitKey::cases())->mapWithKeys(function (LimitKey $key) {
            return [$key->value => collect(['-1', '0', '5'])->mapWithKeys(function (string $value) use ($key) {
                $page = Livewire::test('admin.shops.show', ['shop' => $this->shops()['On Starter']]);
                $this->setLimitOverride($page, $key, $value)->call('saveSubscription');
                $shop = Shop::where('name', 'On Starter')->sole();
                $state = ['errors' => count($page->errors()->keys()), 'shop' => $this->shopState($shop)];
                $shop->update(['limit_overrides' => null, 'products_allowed' => null, 'sales_allowed' => null]);

                return [$value => $state];
            })->all()];
        })->all();

        foreach (['On Full', 'Ungoverned'] as $name) {
            foreach (FeatureKey::cases() as $feature) {
                foreach (['first', 'second'] as $flip) {
                    $page = $this->flipFeature(Livewire::test('admin.shops.show', ['shop' => $this->shops()[$name]]), $feature);
                    $snapshot['admin_flip'][$name][$feature->value][$flip] = ['toasts' => $this->toasts($page), 'shop' => $this->shopState($this->shops()[$name])];
                }
            }
        }

        $onFull = $this->shops()['On Full'];
        $this->flipFeature(Livewire::test('admin.shops.show', ['shop' => $onFull]), FeatureKey::Ads);
        $this->flipFeature(Livewire::test('admin.shops.show', ['shop' => $this->shops()['On Full']]), FeatureKey::Barcode);
        $page = Livewire::test('admin.shops.show', ['shop' => $this->shops()['On Full']]);
        $snapshot['admin_overrides_shown'] = ['text' => $this->pageText($page->html()), 'attributes' => $this->pageAttributes($page->html())];
        $page->call('usePlanDefault', 'ads');
        $snapshot['admin_use_plan_default'] = ['toasts' => $this->toasts($page), 'shop' => $this->shopState($this->shops()['On Full'])];

        foreach (Plan::ordered()->get() as $plan) {
            $editor = Livewire::test('admin.plans')->call('openEdit', $plan->id);
            $form = ['limits' => $editor->get('limits'), 'featureFlags' => $editor->get('featureFlags')];
            $editor->call('save');
            $snapshot['plan_save'][$plan->name] = [
                'form' => $form,
                'errors' => $editor->errors()->keys(),
                'toasts' => $this->toasts($editor),
                'stored' => $this->storedPlan($plan->id),
                'shops' => Shop::where('plan_id', $plan->id)->orderBy('name')->get()->mapWithKeys(fn (Shop $shop) => [$shop->name => $this->shopState($shop)])->all(),
            ];
        }

        $editor = Livewire::test('admin.plans')->call('openCreate');
        $snapshot['plan_create_form'] = ['limits' => $editor->get('limits'), 'featureFlags' => $editor->get('featureFlags')];
        $editor->set('name', 'Created')->set('price', '999')
            ->set('limits.products', '25')->set('limits.whatsapp_messages_per_month', '0')->set('limits.branches', '3')
            ->set('featureFlags.whatsapp_ordering', true)->set('featureFlags.whatsapp_messaging', true)->set('featureFlags.branding', true)
            ->call('save');
        $snapshot['plan_create'] = ['errors' => $editor->errors()->keys(), 'stored' => $this->storedPlan(Plan::where('name', 'Created')->sole()->id)];

        foreach (['branches' => '0', 'products' => '-1', 'sales_per_month' => 'abc'] as $key => $value) {
            $editor = Livewire::test('admin.plans')->call('openCreate')->set('name', 'Invalid')->set('price', '1')->set('limits.'.$key, $value)->call('save');
            $snapshot['plan_validation'][$key] = ['errors' => $editor->errors()->keys(), 'messages' => $editor->errors()->all()];
        }

        $snapshot['after_plan_save'] = $this->entitlements();

        return $snapshot;
    }

    protected function entitlements(): array
    {
        $entitlements = app(Entitlements::class);

        return collect($this->shops())->map(fn (Shop $shop) => [
            'meters' => $entitlements->meters($shop, fresh: true),
            'every_meter' => $entitlements->meters($shop, everyLimit: true),
            'features' => collect($entitlements->features($shop))->map(fn (array $row) => ['key' => $row['key']->value, 'until' => $row['until']?->toIso8601String()] + $row)->all(),
            'shop' => $this->shopState($shop),
        ])->all();
    }

    /** @return array<string, Shop> */
    protected function shops(): array
    {
        return Shop::orderBy('name')->get()->keyBy('name')->all();
    }

    protected function shopState(Shop $shop): array
    {
        $shop = $shop->fresh();
        $columns = collect(FeatureKey::cases())->mapWithKeys(fn (FeatureKey $feature) => [$feature->column() => (bool) $shop->{$feature->column()}])->all();

        return $columns + [
            'limit_overrides' => $shop->limit_overrides,
            'feature_overrides' => $shop->feature_overrides,
            'products_allowed' => $shop->products_allowed,
            'sales_allowed' => $shop->sales_allowed,
            'plan' => $shop->plan?->name,
        ];
    }

    protected function storedPlan(int $id): array
    {
        $row = DB::table('plans')->where('id', $id)->first(['name', 'price', 'billing_interval', 'features', 'is_active', 'is_featured', 'limits', 'feature_flags']);

        return ['limits' => json_decode((string) $row->limits, true), 'feature_flags' => json_decode((string) $row->feature_flags, true), 'features' => json_decode((string) $row->features, true)] + (array) $row;
    }

    protected function pageText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#s', '', $html);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    protected function pageAttributes(string $html): array
    {
        preg_match_all('/\s(aria-label|aria-checked|wire:confirm|placeholder|role)="([^"]*)"/', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => $m[1].'='.html_entity_decode($m[2]), $matches);
    }

    protected function toasts($component): array
    {
        return collect($component->effects['dispatches'] ?? [])->where('name', 'toast')->map(fn (array $d) => $d['params'])->values()->all();
    }

    // The fixture was recorded when the page still had one property per
    // limit (productsAllowed, branchesLimit, …) and one toggleXEnabled()
    // per feature; these reach the generic replacements.
    protected function limitForm($component): array
    {
        return collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => $component->get('limitOverrides.'.$key->value)])->all();
    }

    protected function setLimitOverride($component, LimitKey $key, string $value)
    {
        return $component->set('limitOverrides.'.$key->value, $value);
    }

    protected function flipFeature($component, FeatureKey $feature)
    {
        return $component->call('toggleFeature', $feature->value);
    }

    protected function plan(string $name, array $limits, array $flags): Plan
    {
        return Plan::create(['name' => $name, 'price' => 1000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => $flags]);
    }

    protected function shop(string $name, ?Plan $plan, array $attributes = []): Shop
    {
        $shop = Shop::create(['name' => $name, 'plan_id' => $plan?->id] + $attributes);
        User::factory()->create(['shop_id' => $shop->id, 'name' => "Owner {$name}", 'email' => str($name)->slug().'@example.test']);

        return $shop;
    }

    protected function shopAddon(Shop $shop, Addon $addon, array $attributes): void
    {
        ShopAddon::create([
            'shop_id' => $shop->id, 'addon_id' => $addon->id, 'status' => 'active', 'name' => $addon->name,
            'kind' => $addon->kind, 'price' => $addon->price, 'billing' => $addon->billing, 'starts_at' => now()->subDays(2),
        ] + $attributes);
    }
}
