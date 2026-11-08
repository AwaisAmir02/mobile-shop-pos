<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\ModuleNav;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Design overhaul D2: the Reports hub, module tabs and breadcrumbs that replaced
 * the arrow links at the top of screens (docs/design-system.md). Every card and
 * tab must follow the visibility rule of the link it replaced, and must open.
 */
class ModuleNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const FLAGS = ['expiry_tracking_enabled', 'payroll_enabled', 'ledger_enabled', 'purchase_orders_enabled'];

    private function owner(array $flags = self::FLAGS): User
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $shop->forceFill(array_fill_keys($flags, true))->save();

        return User::factory()->create(['shop_id' => $shop->id]);
    }

    private function staff(array $permissions, array $flags = self::FLAGS): User
    {
        $owner = $this->owner($flags);
        $role = Role::create(['shop_id' => $owner->shop_id, 'name' => 'Staff', 'permissions' => $permissions]);

        return User::factory()->staff()->create(['shop_id' => $owner->shop_id, 'role_id' => $role->id]);
    }

    /** @return list<string> the route names behind the hub's cards */
    private function cards(User $user): array
    {
        $html = $this->actingAs($user)->get(route('reports.index'))->assertOk()->getContent();

        return $this->attributeValues($html, '//a[@data-report-card]', 'data-report-card');
    }

    /** @return list<string> */
    private function tabs(string $html): array
    {
        return $this->attributeValues($html, '//nav//a[@data-module-tab]', 'href');
    }

    /** @return list<string> */
    private function attributeValues(string $html, string $xpath, string $attribute): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $values = [];
        foreach ((new DOMXPath($document))->query($xpath) as $node) {
            $values[] = $node->getAttribute($attribute);
        }

        return $values;
    }

    public function test_the_owner_sees_every_report_card_and_each_one_opens(): void
    {
        $owner = $this->owner();
        $cards = $this->cards($owner);

        $this->assertEqualsCanonicalizing([
            'reports.screens', 'profit.index', 'sales.history',
            'stock-ins.history', 'stock-ins.expiry', 'stock-ins.write-offs',
            'party-ledger.index', 'udhaar.index', 'payroll.history',
            'ledger.profit-and-loss', 'ledger.balance-sheet', 'ledger.trial-balance', 'ledger.cash-book', 'expenses.index',
        ], $cards);

        foreach ($cards as $route) {
            $this->assertLessThan(400, $this->actingAs($owner)->get(route($route))->getStatusCode(), $route);
        }
    }

    public function test_a_reports_only_role_sees_only_the_screen_reports_card(): void
    {
        $this->assertSame(['reports.screens'], $this->cards($this->staff(['reports'])));
    }

    public function test_cards_follow_the_rules_of_the_links_they_replaced(): void
    {
        $this->assertContains('profit.index', $this->cards($this->staff(['reports', 'sales-view-cost-price'])));

        $stock = $this->cards($this->staff(['reports', 'stock-ins']));
        $this->assertContains('stock-ins.expiry', $stock);
        $this->assertContains('stock-ins.write-offs', $stock);

        $noExpiry = $this->cards($this->staff(['reports', 'stock-ins'], ['payroll_enabled']));
        $this->assertNotContains('stock-ins.expiry', $noExpiry);
        $this->assertContains('stock-ins.write-offs', $noExpiry);

        $this->assertNotContains('payroll.history', $this->cards($this->staff(['reports', 'payroll'], [])));
        $this->assertContains('payroll.history', $this->cards($this->staff(['reports', 'payroll'])));

        // LEDGER_FORCE_ON starts the ledger on every shop (tests/TestCase.php), so "off" can't be tested then.
        if (! env('LEDGER_FORCE_ON')) {
            $this->assertNotContains('ledger.trial-balance', $this->cards($this->staff(['reports', 'ledger-reports'], [])));
        }
        $this->assertContains('ledger.trial-balance', $this->cards($this->staff(['reports', 'ledger-reports'])));
    }

    public function test_the_screen_report_builder_moved_to_its_own_address_with_the_same_guard(): void
    {
        $this->actingAs($this->staff(['reports']))->get(route('reports.screens'))->assertOk()->assertSee('Screen reports');
        $this->actingAs($this->staff(['sales']))->get(route('reports.screens'))->assertForbidden();
        $this->actingAs($this->staff(['sales']))->get(route('reports.index'))->assertForbidden();
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function moduleTabs(): array
    {
        return [
            'sales register' => ['sales.index', ['sales'], ['sales.index', 'sales.history']],
            'sales history' => ['sales.history', ['sales'], ['sales.index', 'sales.history']],
            'stock receipts' => ['stock-ins.history', ['stock-ins'], ['stock-ins.history', 'stock-ins.expiry', 'stock-ins.write-offs']],
            'stock write-offs' => ['stock-ins.write-offs', ['stock-ins'], ['stock-ins.history', 'stock-ins.expiry', 'stock-ins.write-offs']],
            'purchasing, every role' => ['purchase-orders.index', ['purchase-orders', 'receive-goods', 'vendor-invoices'], ['purchase-orders.index', 'purchase-orders.receipts', 'purchase-orders.invoices']],
            'purchasing, approver only' => ['purchase-orders.invoices', ['vendor-invoices-approve'], ['purchase-orders.index', 'purchase-orders.invoices']],
        ];
    }

    #[DataProvider('moduleTabs')]
    public function test_module_tabs_show_the_sections_this_user_can_open(string $route, array $permissions, array $expected): void
    {
        $html = $this->actingAs($this->staff($permissions))->get(route($route))->assertOk()->getContent();

        $this->assertSame(array_map(fn ($name) => route($name), $expected), $this->tabs($html));

        foreach ($expected as $tab) {
            $this->assertLessThan(400, $this->get(route($tab))->getStatusCode(), $tab);
        }
    }

    public function test_expiry_tab_needs_expiry_tracking(): void
    {
        $html = $this->actingAs($this->staff(['stock-ins'], []))->get(route('stock-ins.history'))->getContent();

        $this->assertSame([route('stock-ins.history'), route('stock-ins.write-offs')], $this->tabs($html));
    }

    public function test_a_single_tab_is_not_shown(): void
    {
        $user = $this->staff(['payroll-run']);

        $this->assertCount(1, ModuleNav::tabs('payroll', $user));
        $this->assertSame([], $this->tabs($this->actingAs($user)->get(route('payroll.run'))->getContent()));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forms(): array
    {
        return [
            'stock in' => ['stock-ins', 'stock-ins'],
            'balance loads' => ['balance-loads', 'balance-loads'],
            'wallet loads' => ['wallet-loads', 'wallet-loads'],
            'bills' => ['bills', 'bills'],
            'sim sales' => ['sim-sales', 'sim-sales'],
            'nadra' => ['nadra-verifications', 'nadra-verifications'],
            'repairs' => ['repairs', 'repairs'],
            'services' => ['services', 'services'],
        ];
    }

    #[DataProvider('forms')]
    public function test_forms_lead_back_to_their_list_with_a_breadcrumb_not_an_arrow_link(string $module, string $permission): void
    {
        $user = $this->staff([$permission], ['services_enabled']);
        $html = $this->actingAs($user)->get(route("$module.index"))->assertOk()->getContent();

        $this->assertSame([route("$module.history")], $this->attributeValues($html, '//a[@data-breadcrumb]', 'href'));
        $this->assertStringNotContainsString('History →', $html);
    }

    /**
     * A screen's header slot renders in the layout, outside the Livewire
     * component, so a wire: action there silently does nothing. Payroll's
     * Add Employee button had this bug until design overhaul D3.
     */
    public function test_no_header_slot_holds_a_livewire_action(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/livewire'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/<x-slot name="header">(.*?)<\/x-slot>/s', file_get_contents($file->getPathname()), $slots);

            foreach ($slots[1] as $slot) {
                if (preg_match('/wire:(click|model|submit|change|keydown)/', $slot)) {
                    $offenders[] = str_replace(resource_path('views/livewire').DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'Move these actions into the component body; the header renders outside it.');
    }

    /**
     * Livewire wraps each @if and @foreach in marker comments and patches the
     * page by them. @continue jumps past an @endif without writing its marker,
     * the page stops updating after the next change, and the browser logs
     * "Cannot read properties of null (reading 'before')". This hit Products
     * and the Sales register search (fixed in design overhaul D5).
     */
    public function test_no_livewire_view_uses_continue(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/livewire'))) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php') && preg_match('/@continue\b/', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'Use @if/@else instead of @continue in Livewire views.');
    }

    public function test_the_users_screen_is_titled_team(): void
    {
        $this->actingAs($this->owner())->get(route('users.index'))
            ->assertOk()
            ->assertSee('<title>Team', false)
            ->assertSee('Manage roles');
    }
}
