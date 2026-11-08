<?php

namespace Tests\Feature;

use App\Enums\ShopScreen;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins who sees which sidebar link, and where it goes, for the design overhaul
 * (docs/design-system.md). Restyling and regrouping the sidebar must not change
 * any of this by accident.
 *
 * Compares sets, so grouping and order are free to change. A deliberate change
 * to a label or a target, such as a rename or pointing a link at a list screen,
 * is made here in the same stage and listed in that stage's report.
 */
class SidebarVisibilityGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FLAGS = [
        'services_enabled',
        'ledger_enabled',
        'payroll_enabled',
        'purchase_orders_enabled',
        'whatsapp_ordering_enabled',
        'whatsapp_messaging_enabled',
        'online_store_enabled',
        'social_enabled',
        'ads_enabled',
    ];

    private const BASE_LINKS = [
        'Dashboard' => '/dashboard',
        'Products' => '/products',
        'Sales' => '/sales',
        'Wallet Loads' => '/wallet-loads',
        'NADRA Verification' => '/nadra-verifications',
        'Expenses' => '/expenses',
        'SIM Sale' => '/sim-sales',
        'Udhaar' => '/udhaar',
        'Balance Loads' => '/balance-loads',
        'Bills' => '/bills',
        'Stock In' => '/stock-ins',
        'Vendors' => '/vendors',
        'Customers' => '/customers',
        'Repairs' => '/repairs',
        'Reports' => '/reports',
        'Gross Profit' => '/gross-profit',
        'Party Ledger' => '/party-ledger',
        'Team' => '/users',
        'Branches' => '/branches',
        'Settings' => '/settings',
        'Plan & Usage' => '/billing',
    ];

    private const FLAG_LINKS = [
        'Services' => '/services',
        'Purchase Orders' => '/purchase-orders',
        'WhatsApp Orders' => '/whatsapp-orders',
        'Online Store Orders' => '/online-orders',
        'Customer Messages' => '/customer-messages',
        'Marketing' => '/marketing',
        'Ledger' => '/ledger/trial-balance',
        'Payroll' => '/payroll/employees',
    ];

    public function test_owner_with_every_feature_on_sees_every_link(): void
    {
        $this->assertSameLinks(self::BASE_LINKS + self::FLAG_LINKS, $this->linksFor($this->owner(self::FLAGS)));
    }

    public function test_owner_with_every_feature_off_sees_only_the_base_links(): void
    {
        $this->assertSameLinks(self::BASE_LINKS, $this->linksFor($this->owner([])));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function singleFlagLinks(): array
    {
        return [
            'services' => ['services_enabled', 'Services'],
            'ledger' => ['ledger_enabled', 'Ledger'],
            'payroll' => ['payroll_enabled', 'Payroll'],
            'purchase orders' => ['purchase_orders_enabled', 'Purchase Orders'],
            'whatsapp ordering' => ['whatsapp_ordering_enabled', 'WhatsApp Orders'],
            'online store' => ['online_store_enabled', 'Online Store Orders'],
            'whatsapp messaging' => ['whatsapp_messaging_enabled', 'Customer Messages'],
        ];
    }

    #[DataProvider('singleFlagLinks')]
    public function test_turning_a_feature_off_hides_only_its_own_link(string $flag, string $label): void
    {
        $expected = self::BASE_LINKS + self::FLAG_LINKS;
        unset($expected[$label]);

        $this->assertSameLinks($expected, $this->linksFor($this->owner(array_values(array_diff(self::FLAGS, [$flag])))));
    }

    public function test_marketing_needs_whatsapp_or_social(): void
    {
        $this->assertArrayNotHasKey('Marketing', $this->linksFor($this->owner(['ads_enabled'])));

        foreach (['whatsapp_ordering_enabled', 'whatsapp_messaging_enabled', 'social_enabled'] as $flag) {
            $this->assertSame('/marketing', $this->linksFor($this->owner([$flag]))['Marketing'] ?? null, $flag);
        }
    }

    /**
     * Every screen permission and the links holding only that one should give,
     * with every feature on. Must list every ShopScreen case, see the test below.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function linksByPermission(): array
    {
        return [
            'dashboard' => [['Dashboard' => '/dashboard']],
            'products' => [['Products' => '/products']],
            'stock-ins' => [['Stock In' => '/stock-ins']],
            'vendors' => [['Vendors' => '/vendors']],
            'customers' => [['Customers' => '/customers']],
            'sales' => [['Sales' => '/sales']],
            'sales-edit-cancel' => [[]],
            'sales-view-cost-price' => [[]],
            'sim-sales' => [['SIM Sale' => '/sim-sales']],
            'udhaar' => [['Udhaar' => '/udhaar']],
            'balance-loads' => [['Balance Loads' => '/balance-loads']],
            'wallet-loads' => [['Wallet Loads' => '/wallet-loads']],
            'shop-accounts' => [[]],
            'bills' => [['Bills' => '/bills']],
            'bills-edit-cancel' => [[]],
            'repairs' => [['Repairs' => '/repairs']],
            'repairs-edit-cancel' => [[]],
            'nadra-verifications' => [['NADRA Verification' => '/nadra-verifications']],
            'nadra-verifications-edit-cancel' => [[]],
            'expenses' => [['Expenses' => '/expenses']],
            'expenses-edit-cancel' => [[]],
            'stock-ins-edit-cancel' => [[]],
            'wallet-loads-edit-cancel' => [[]],
            'balance-loads-edit-cancel' => [[]],
            'sim-sales-edit-cancel' => [[]],
            'settings' => [['Settings' => '/settings']],
            'reports' => [['Reports' => '/reports']],
            'users' => [['Team' => '/users', 'Settings' => '/settings']],
            'party-ledger' => [['Party Ledger' => '/party-ledger']],
            'settings-categories' => [[]],
            'settings-networks' => [[]],
            'settings-bill-config' => [[]],
            'settings-percentage' => [[]],
            'settings-brands' => [[]],
            'settings-branding' => [[]],
            'services' => [['Services' => '/services']],
            'services-edit-cancel' => [[]],
            'settings-services' => [[]],
            'whatsapp-orders' => [['WhatsApp Orders' => '/whatsapp-orders']],
            'whatsapp-orders-decide' => [[]],
            'settings-integrations' => [[]],
            'whatsapp-messages' => [['Customer Messages' => '/customer-messages']],
            'marketing' => [['Marketing' => '/marketing/posts']],
            'marketing-analytics' => [['Marketing' => '/marketing']],
            'marketing-ads' => [['Marketing' => '/marketing/ads']],
            'branches' => [['Branches' => '/branches']],
            'purchase-orders' => [['Purchase Orders' => '/purchase-orders']],
            'receive-goods' => [['Purchase Orders' => '/purchase-orders']],
            'vendor-invoices' => [['Purchase Orders' => '/purchase-orders']],
            'vendor-invoices-approve' => [['Purchase Orders' => '/purchase-orders']],
            'payroll' => [['Payroll' => '/payroll/employees']],
            'payroll-run' => [['Payroll' => '/payroll/run']],
            'ledger-reports' => [['Ledger' => '/ledger/trial-balance']],
            'online-orders' => [['Online Store Orders' => '/online-orders']],
            'online-orders-decide' => [[]],
        ];
    }

    public function test_every_screen_permission_is_covered(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (ShopScreen $screen) => $screen->value, ShopScreen::cases()),
            array_keys(self::linksByPermission()),
            'A new ShopScreen needs a decision here: which sidebar link, if any, does it give?',
        );
    }

    #[DataProvider('linksByPermission')]
    public function test_a_single_permission_shows_exactly_its_links(array $expected): void
    {
        $permission = $this->dataName();

        $this->assertSameLinks($expected, $this->linksFor($this->staff([$permission], self::FLAGS)));
    }

    #[DataProvider('linksByPermission')]
    public function test_every_link_a_single_permission_shows_opens_for_that_user(array $expected): void
    {
        $this->actingAs($this->staff([$this->dataName()], self::FLAGS));

        foreach ($expected as $label => $path) {
            $this->assertLessThan(400, $this->get($path)->getStatusCode(), "$label should open at $path");
        }

        $this->addToAssertionCount(1);
    }

    public function test_every_link_opens_for_an_owner_with_every_feature_on(): void
    {
        $this->actingAs($this->owner(self::FLAGS));

        foreach (self::BASE_LINKS + self::FLAG_LINKS as $label => $path) {
            $this->assertLessThan(400, $this->get($path)->getStatusCode(), "$label should open at $path");
        }
    }

    public function test_gross_profit_needs_reports_and_cost_price_access(): void
    {
        $links = $this->linksFor($this->staff(['reports', 'sales-view-cost-price'], self::FLAGS));

        $this->assertSameLinks(['Reports' => '/reports', 'Gross Profit' => '/gross-profit'], $links);
    }

    public function test_marketing_ads_permission_with_ads_off_shows_no_marketing_link(): void
    {
        $flags = array_values(array_diff(self::FLAGS, ['ads_enabled']));

        // Fixed in design overhaul D1 with the owner's approval: this used to link
        // to Posts, which the user can't open.
        $this->assertSameLinks([], $this->linksFor($this->staff(['marketing-ads'], $flags)));

        // The screens themselves are still guarded as before.
        $this->get('/marketing/posts')->assertForbidden();
        $this->get('/marketing/ads')->assertForbidden();
    }

    public function test_marketing_overview_wins_when_a_user_holds_several_marketing_permissions(): void
    {
        $links = $this->linksFor($this->staff(['marketing', 'marketing-analytics', 'marketing-ads'], self::FLAGS));

        $this->assertSameLinks(['Marketing' => '/marketing'], $links);
    }

    public function test_plan_and_usage_is_owner_only_even_for_staff_holding_every_permission(): void
    {
        $every = array_map(fn (ShopScreen $screen) => $screen->value, ShopScreen::cases());
        $expected = self::BASE_LINKS + self::FLAG_LINKS;
        unset($expected['Plan & Usage']);

        $this->assertSameLinks($expected, $this->linksFor($this->staff($every, self::FLAGS)));
    }

    public function test_disabled_and_hidden_screens_drop_their_links_even_for_an_owner(): void
    {
        foreach (['disabled_screens', 'hidden_screens'] as $column) {
            $owner = $this->owner(self::FLAGS);
            $owner->shop->forceFill([$column => ['products', 'reports']])->save();

            $links = $this->linksFor($owner->fresh());

            $this->assertArrayNotHasKey('Products', $links, $column);
            $this->assertArrayNotHasKey('Reports', $links, $column);
            $this->assertArrayNotHasKey('Gross Profit', $links, $column);
            $this->assertArrayHasKey('Sales', $links, $column);
        }
    }

    public function test_super_admin_sees_only_the_admin_links(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);

        $this->assertSameLinks([
            'Shops' => '/admin',
            'Plans' => '/admin/plans',
            'Add-ons' => '/admin/addons',
            'Payments' => '/admin/payments',
            'Integrations' => '/admin/integrations',
            'Backups' => '/admin/backups',
        ], $this->linksFor($admin));
    }

    /**
     * @param  list<string>  $flags
     */
    private function owner(array $flags): User
    {
        return User::factory()->create(['shop_id' => $this->shopWith($flags)->id]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $flags
     */
    private function staff(array $permissions, array $flags): User
    {
        $shop = $this->shopWith($flags);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Guard role', 'permissions' => $permissions]);

        return User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    /**
     * @param  list<string>  $flags
     */
    private function shopWith(array $flags): Shop
    {
        $values = array_fill_keys(self::FLAGS, false);

        foreach ($flags as $flag) {
            $values[$flag] = true;
        }

        $shop = Shop::create(['name' => 'Guard shop']);
        $shop->forceFill($values)->save();

        return $shop;
    }

    /**
     * Reads the real rendered sidebar. Profile opens for every signed-in user,
     * including a role with no screens at all.
     *
     * @return array<string, string> label => path
     */
    private function linksFor(User $user): array
    {
        $html = $this->actingAs($user)->get(route('profile'))->assertOk()->getContent();

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = [];

        foreach ((new DOMXPath($document))->query('//aside//a[@data-nav-item]') as $link) {
            $label = $link->getAttribute('title');
            $this->assertArrayNotHasKey($label, $links, "$label appears twice in the sidebar");
            $links[$label] = parse_url($link->getAttribute('href'), PHP_URL_PATH) ?? '/';
        }

        return $links;
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     */
    private function assertSameLinks(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }
}
