<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ShopScreen;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\Guide;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionFunction;
use Tests\TestCase;

/**
 * The per-screen guide behind the header "?" button. Files live in
 * resources/guides/{en,ur}/{route}.php; the two languages must describe the
 * same screen in the same shape, and each section or item can be limited to
 * the people it applies to.
 */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shop screens that still have no guide. Lower it as each wave lands;
     * the failure message lists the screens without one.
     */
    private const SCREENS_WITHOUT_GUIDE_BUDGET = 51;

    /** Wave 1, as approved. */
    private const WAVE_ONE = [
        'sales.index', 'sales.show', 'sales.history', 'dashboard', 'products.index', 'products.show',
        'stock-ins.index', 'customers.index', 'customers.show', 'udhaar.index', 'udhaar.show',
    ];

    /** Wave 2: counter services (form and history), Expenses, Vendors, Party Ledger. */
    private const WAVE_TWO = [
        'repairs.index', 'repairs.history', 'bills.index', 'bills.history', 'wallet-loads.index', 'wallet-loads.history',
        'balance-loads.index', 'balance-loads.history', 'sim-sales.index', 'sim-sales.history',
        'nadra-verifications.index', 'nadra-verifications.history', 'services.index', 'services.history',
        'expenses.index', 'vendors.index', 'vendors.show', 'party-ledger.index',
    ];

    private Shop $shop;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Ali Mobiles']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id]);

        // Can sell and see customers, but not edit or cancel sales or see cost prices.
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Cashier', 'permissions' => ['sales', 'customers', 'products']]);
        $this->cashier = User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    private function sectionIds(?array $guide, string $language = 'en'): array
    {
        return collect($guide['languages'][$language]['sections'] ?? [])->pluck('id')->all();
    }

    private function labels(?array $guide, string $language = 'en'): array
    {
        return collect($guide['languages'][$language]['sections'] ?? [])->flatMap(fn ($section) => collect($section['items'])->pluck('label'))->all();
    }

    public function test_wave_one_screens_all_have_a_guide_and_every_guide_names_a_real_shop_screen(): void
    {
        foreach ([...self::WAVE_ONE, ...self::WAVE_TWO] as $route) {
            $this->assertTrue(Guide::covers($route), "No guide for {$route}");
        }

        foreach (Guide::SHARED as $route => [$key, $section]) {
            $this->assertNotNull(Route::getRoutes()->getByName($route), "Shared guide route {$route} doesn't exist");
            $this->assertTrue(Guide::exists($key), "{$route} shares a guide that doesn't exist: {$key}");
            foreach (array_keys(Guide::LANGUAGES) as $language) {
                $this->assertContains($section, array_column(Guide::load($key, $language)['sections'], 'id'), "{$language}/{$key} has no '{$section}' section for {$route}");
            }
        }

        foreach (Guide::keys() as $key) {
            $route = Route::getRoutes()->getByName($key);
            $this->assertNotNull($route, "Guide {$key} doesn't match a route name");
            $this->assertContains('shop.user', $route->gatherMiddleware(), "Guide {$key} is for a screen shop users don't see");
        }
    }

    public function test_both_languages_have_the_same_shape_and_valid_conditions(): void
    {
        $screens = array_column(ShopScreen::cases(), 'value');
        $features = array_column(FeatureKey::cases(), 'value');
        $condition = fn (array $node) => array_intersect_key($node, array_flip(['needs', 'feature', 'owner']));

        foreach (Guide::keys() as $key) {
            $english = Guide::load($key, 'en');

            foreach (array_keys(Guide::LANGUAGES) as $language) {
                $this->assertFileExists(Guide::path($language, $key), "{$key} has no {$language} file");
                $guide = Guide::load($key, $language);
                $where = "{$language}/{$key}";

                $this->assertNotEmpty($guide['title'] ?? null, "{$where}: title");
                $this->assertNotEmpty($guide['summary'] ?? null, "{$where}: summary");
                $this->assertSame(array_column($english['sections'], 'id'), array_column($guide['sections'], 'id'), "{$where}: section ids differ from English");

                foreach ($guide['sections'] as $index => $section) {
                    $source = $english['sections'][$index];
                    $here = "{$where} #{$section['id']}";

                    $this->assertNotEmpty($section['heading'] ?? null, "{$here}: heading");
                    $this->assertSame($condition($source), $condition($section), "{$here}: section conditions differ from English");
                    $this->assertSame(count($source['steps'] ?? []), count($section['steps'] ?? []), "{$here}: step count differs");
                    $this->assertSame(count($source['items'] ?? []), count($section['items'] ?? []), "{$here}: item count differs");
                    $this->assertSame(filled($source['intro'] ?? null), filled($section['intro'] ?? null), "{$here}: intro in one language only");

                    foreach ($section['steps'] ?? [] as $i => $step) {
                        $sourceStep = $source['steps'][$i];
                        $this->assertNotEmpty(is_array($step) ? $step['text'] : $step, "{$here}: empty step");
                        $this->assertSame($condition((array) (is_array($sourceStep) ? $sourceStep : [])), $condition((array) (is_array($step) ? $step : [])), "{$here}: step {$i} conditions differ");
                    }

                    foreach ($section['items'] ?? [] as $i => $item) {
                        $this->assertNotEmpty($item['label'] ?? null, "{$here}: item {$i} label");
                        $this->assertNotEmpty($item['text'] ?? null, "{$here}: item {$i} text");
                        // Labels are what's printed on the screen, so they match in every language.
                        $this->assertSame($source['items'][$i]['label'], $item['label'], "{$here}: item {$i} label differs from English");
                        $this->assertSame($condition($source['items'][$i]), $condition($item), "{$here}: item {$i} conditions differ");
                    }

                    foreach ([$section, ...($section['items'] ?? []), ...array_filter($section['steps'] ?? [], 'is_array')] as $node) {
                        foreach ((array) ($node['needs'] ?? []) as $needs) {
                            $this->assertContains($needs, $screens, "{$here}: unknown screen permission {$needs}");
                        }
                        if (isset($node['feature'])) {
                            $this->assertContains($node['feature'], $features, "{$here}: unknown feature {$node['feature']}");
                        }
                    }
                }
            }
        }
    }

    public function test_the_header_button_and_panel_show_on_a_screen_with_a_guide_only(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-guide-button', false)
            ->assertSee('data-guide-panel', false)
            ->assertSee('data-guide="dashboard"', false)
            ->assertSee('Net (Revenue − Expenses)')
            ->assertSee('Waqt chunna')
            ->assertSee('Roman Urdu')
            ->assertSee('data-guide-tooltip', false);

        $this->get(route('support.index'))
            ->assertOk()
            ->assertDontSee('data-guide-button', false)
            ->assertDontSee('data-guide-panel', false);
    }

    public function test_staff_see_only_the_parts_that_apply_to_them(): void
    {
        $ownerGuide = Guide::forUser('sales.show', $this->owner);
        $cashierGuide = Guide::forUser('sales.show', $this->cashier);

        $this->assertContains('changes', $this->sectionIds($ownerGuide));
        $this->assertNotContains('changes', $this->sectionIds($cashierGuide));
        $this->assertNotContains('changes', $this->sectionIds($cashierGuide, 'ur'));

        $this->assertContains('ⓘ next to the name', $this->labels(Guide::forUser('sales.index', $this->owner)));
        $this->assertNotContains('ⓘ next to the name', $this->labels(Guide::forUser('sales.index', $this->cashier)));

        // Items in Sales History's row menu need edit/cancel too.
        $this->assertContains('Delete', $this->labels(Guide::forUser('sales.history', $this->owner)));
        $this->assertNotContains('Delete', $this->labels(Guide::forUser('sales.history', $this->cashier)));

        $this->actingAs($this->cashier)->get(route('sales.history'))
            ->assertOk()
            ->assertSee('Only on sales with money still due.')
            ->assertDontSee('Asks first, then cancels the sale and puts the stock back.');
    }

    public function test_parts_for_switched_off_features_are_left_out(): void
    {
        $this->shop->update(['barcode_enabled' => false, 'variants_enabled' => false, 'expiry_tracking_enabled' => false]);
        $this->owner->refresh();

        $guide = Guide::forUser('products.index', $this->owner);
        $this->assertNotContains('labels', $this->sectionIds($guide));
        $this->assertNotContains('Search by barcode', $this->labels($guide));
        $this->assertNotContains('Shelf Life (days)', $this->labels($guide));
        $addProduct = collect($guide['languages']['en']['sections'])->firstWhere('id', 'add-product');
        $this->assertCount(4, $addProduct['steps'], 'The variants step is left out');

        $this->shop->update(['barcode_enabled' => true, 'variants_enabled' => true, 'expiry_tracking_enabled' => true]);
        $this->owner->refresh();

        $guide = Guide::forUser('products.index', $this->owner);
        $this->assertContains('labels', $this->sectionIds($guide));
        $this->assertContains('Search by barcode', $this->labels($guide));
        $this->assertContains('Shelf Life (days)', $this->labels($guide));
        $this->assertCount(5, collect($guide['languages']['ur']['sections'])->firstWhere('id', 'add-product')['steps']);
    }

    public function test_a_history_screen_opens_its_service_guide_at_the_history_part(): void
    {
        $guide = Guide::forUser('repairs.history', $this->owner);
        $this->assertSame('repairs.index', $guide['key']);
        $this->assertSame('history', $guide['section']);
        $this->assertNull(Guide::forUser('repairs.index', $this->owner)['section']);

        $this->actingAs($this->owner)->get(route('repairs.history'))
            ->assertOk()
            ->assertSee('data-guide="repairs.index"', false)
            ->assertSee("open-guide.window=\"show(\$event.detail?.section ?? 'history')\"", false);

        // Edit/Delete on a counter service need that service's edit/cancel permission.
        $this->assertContains('Delete', $this->labels(Guide::forUser('repairs.history', $this->owner)));
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Counter', 'permissions' => ['repairs']]);
        $counter = User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->assertNotContains('Delete', $this->labels(Guide::forUser('repairs.history', $counter)));
        $this->assertContains('Add Payment', $this->labels(Guide::forUser('repairs.history', $counter)));
    }

    public function test_super_admins_and_guests_get_no_guide(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true, 'is_owner' => false]);

        $this->assertNull(Guide::forUser('dashboard', $admin));
        $this->assertNull(Guide::forUser('dashboard', null));
        $this->assertNull(Guide::forUser('../../.env', $this->owner));
        $this->assertNull(Guide::forUser(null, $this->owner));
    }

    public function test_the_add_product_form_links_to_its_part_of_the_guide(): void
    {
        $this->actingAs($this->owner)->get(route('products.index'))
            ->assertOk()
            ->assertSee('data-guide-link', false)
            ->assertSee("section: 'add-product'", false)
            ->assertSee('data-guide-section="add-product"', false);
    }

    public function test_shop_screens_without_a_guide_stay_within_budget(): void
    {
        $missing = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) {
                $uses = $route->getAction('uses');

                return in_array('GET', $route->methods(), true)
                    && $route->getName()
                    && in_array('shop.user', $route->gatherMiddleware(), true)
                    && $uses instanceof Closure
                    && str_contains((new ReflectionFunction($uses))->getFileName(), 'volt');
            })
            ->map->getName()
            ->reject(fn (string $name) => Guide::covers($name))
            ->sort()
            ->values();

        $this->assertLessThanOrEqual(
            self::SCREENS_WITHOUT_GUIDE_BUDGET,
            $missing->count(),
            "More shop screens without a guide than the budget allows. Add a guide, or raise the budget on purpose.\nWithout a guide: ".$missing->implode(', ')
        );
    }
}
