<?php

namespace App\Enums;

/**
 * What the Super Admin turns on or off for a shop: one switch per module.
 * A module is its main screen plus the permissions and settings tabs that
 * only exist for it (Edit / Cancel, Accept / Reject, its settings tab...),
 * so they can never be left on or off on their own. Roles still grant those
 * permissions one by one (ShopScreen::grouped()).
 *
 * The value is the main screen's value, which is what disabled_screens
 * stores. A module backed by a FeatureKey is switched by that feature alone.
 */
enum ShopModule: string
{
    case Dashboard = 'dashboard';
    case Products = 'products';
    case Customers = 'customers';
    case Reports = 'reports';
    case PartyLedger = 'party-ledger';
    case StockIns = 'stock-ins';
    case PurchaseOrders = 'purchase-orders';
    case Vendors = 'vendors';
    case Sales = 'sales';
    case SimSales = 'sim-sales';
    case BalanceLoads = 'balance-loads';
    case WalletLoads = 'wallet-loads';
    case Bills = 'bills';
    case Repairs = 'repairs';
    case NadraVerifications = 'nadra-verifications';
    case Udhaar = 'udhaar';
    case Expenses = 'expenses';
    case Services = 'services';
    case WhatsAppOrders = 'whatsapp-orders';
    case OnlineOrders = 'online-orders';
    case WhatsAppMessages = 'whatsapp-messages';
    case Marketing = 'marketing';
    case MarketingAds = 'marketing-ads';
    case Users = 'users';
    case ShopAccounts = 'shop-accounts';
    case CashBalances = 'cash-balances';
    case Branches = 'branches';
    case Payroll = 'payroll';
    case LedgerReports = 'ledger-reports';
    case Settings = 'settings';
    case SettingsCategories = 'settings-categories';
    case SettingsBrands = 'settings-brands';
    case SettingsPercentage = 'settings-percentage';
    case SettingsBranding = 'settings-branding';
    case SettingsIntegrations = 'settings-integrations';

    /** @return array<int, ShopScreen> the main screen first */
    public function screens(): array
    {
        return match ($this) {
            self::StockIns => [ShopScreen::StockIns, ShopScreen::StockInsEditCancel],
            self::PurchaseOrders => [ShopScreen::PurchaseOrders, ShopScreen::ReceiveGoods, ShopScreen::VendorInvoices, ShopScreen::VendorInvoicesApprove],
            self::Sales => [ShopScreen::Sales, ShopScreen::SalesEditCancel, ShopScreen::SalesViewCostPrice],
            self::SimSales => [ShopScreen::SimSales, ShopScreen::SimSalesEditCancel, ShopScreen::SettingsNetworks],
            self::BalanceLoads => [ShopScreen::BalanceLoads, ShopScreen::BalanceLoadsEditCancel],
            self::WalletLoads => [ShopScreen::WalletLoads, ShopScreen::WalletLoadsEditCancel],
            self::Bills => [ShopScreen::Bills, ShopScreen::BillsEditCancel, ShopScreen::SettingsBillConfig],
            self::Repairs => [ShopScreen::Repairs, ShopScreen::RepairsEditCancel],
            self::NadraVerifications => [ShopScreen::NadraVerifications, ShopScreen::NadraVerificationsEditCancel],
            self::Expenses => [ShopScreen::Expenses, ShopScreen::ExpensesEditCancel],
            self::Services => [ShopScreen::Services, ShopScreen::ServicesEditCancel, ShopScreen::SettingsServices],
            self::WhatsAppOrders => [ShopScreen::WhatsAppOrders, ShopScreen::WhatsAppOrdersDecide],
            self::OnlineOrders => [ShopScreen::OnlineOrders, ShopScreen::OnlineOrdersDecide],
            self::Marketing => [ShopScreen::Marketing, ShopScreen::MarketingAnalytics],
            self::Payroll => [ShopScreen::Payroll, ShopScreen::PayrollRun],
            default => [ShopScreen::from($this->value)],
        };
    }

    /** @return array<int, string> */
    public function screenValues(): array
    {
        return array_map(fn (ShopScreen $screen) => $screen->value, $this->screens());
    }

    public function mainScreen(): ShopScreen
    {
        return $this->screens()[0];
    }

    /** The feature that switches this module, when it has one. */
    public function feature(): ?FeatureKey
    {
        return match ($this) {
            self::PurchaseOrders => FeatureKey::PurchaseOrders,
            self::Services => FeatureKey::Services,
            self::WhatsAppOrders => FeatureKey::WhatsAppOrdering,
            self::OnlineOrders => FeatureKey::OnlineStore,
            self::WhatsAppMessages => FeatureKey::WhatsAppMessaging,
            self::MarketingAds => FeatureKey::Ads,
            self::Payroll => FeatureKey::Payroll,
            self::LedgerReports => FeatureKey::Ledger,
            self::SettingsBranding => FeatureKey::Branding,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PurchaseOrders => 'Purchase Orders, Goods Receipts & Vendor Invoices',
            self::OnlineOrders => 'Online Store',
            self::WhatsAppMessages => 'Customer Messages (reminders, repair ready)',
            self::MarketingAds => 'Meta Ads (spends money)',
            self::Payroll => 'Payroll (shows salaries)',
            self::LedgerReports => 'Double-Entry Ledger',
            self::SettingsBranding => 'Branding (own logo on receipts)',
            default => $this->mainScreen()->label(),
        };
    }

    /** What the switch also covers, in words, for modules with more than one screen. */
    public function includesLabel(): ?string
    {
        $extra = array_slice($this->screens(), 1);
        $prefix = str($this->mainScreen()->label())->before(':')->toString().': ';

        return $extra === [] ? null : 'Includes '.collect($extra)->map(fn (ShopScreen $screen) => match (true) {
            str_starts_with($screen->label(), $prefix) => substr($screen->label(), strlen($prefix)),
            str_starts_with($screen->label(), 'Settings: ') => substr($screen->label(), strlen('Settings: ')).' settings',
            default => $screen->label(),
        })->implode(', ');
    }

    public static function forScreen(ShopScreen|string $screen): ?self
    {
        static $map = null;
        $map ??= collect(self::cases())->flatMap(fn (self $module) => array_fill_keys($module->screenValues(), $module))->all();

        return $map[$screen instanceof ShopScreen ? $screen->value : $screen] ?? null;
    }

    public static function forFeature(FeatureKey $feature): ?self
    {
        return collect(self::cases())->first(fn (self $module) => $module->feature() === $feature);
    }

    /** @return array<string, array<int, self>> */
    public static function grouped(): array
    {
        return [
            'Core' => [self::Dashboard, self::Products, self::Customers, self::Reports, self::PartyLedger],
            'Inventory & Sales' => [self::StockIns, self::PurchaseOrders, self::Vendors, self::Sales, self::SimSales],
            'Financial Services' => [self::BalanceLoads, self::WalletLoads, self::Bills, self::Repairs, self::NadraVerifications, self::Udhaar, self::Expenses, self::Services],
            'Online Selling' => [self::WhatsAppOrders, self::OnlineOrders, self::WhatsAppMessages, self::Marketing, self::MarketingAds],
            'Administration' => [self::Users, self::ShopAccounts, self::CashBalances, self::Branches, self::Payroll, self::LedgerReports],
            'Settings' => [self::Settings, self::SettingsCategories, self::SettingsBrands, self::SettingsPercentage, self::SettingsBranding, self::SettingsIntegrations],
        ];
    }
}
