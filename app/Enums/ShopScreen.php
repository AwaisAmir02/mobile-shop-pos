<?php

namespace App\Enums;

enum ShopScreen: string
{
    case Dashboard = 'dashboard';
    case Products = 'products';
    case StockIns = 'stock-ins';
    case Customers = 'customers';
    case Sales = 'sales';
    case SimSales = 'sim-sales';
    case Udhaar = 'udhaar';
    case BalanceLoads = 'balance-loads';
    case WalletLoads = 'wallet-loads';
    case ShopAccounts = 'shop-accounts';
    case Bills = 'bills';
    case Repairs = 'repairs';
    case NadraVerifications = 'nadra-verifications';
    case Expenses = 'expenses';
    case Settings = 'settings';
    case Reports = 'reports';
    case Users = 'users';
    case PartyLedger = 'party-ledger';
    case SettingsCategories = 'settings-categories';
    case SettingsNetworks = 'settings-networks';
    case SettingsBillConfig = 'settings-bill-config';
    case SettingsPercentage = 'settings-percentage';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Products => 'Products',
            self::StockIns => 'Stock In',
            self::Customers => 'Customers',
            self::Sales => 'Sales',
            self::SimSales => 'SIM Sale',
            self::Udhaar => 'Udhaar',
            self::BalanceLoads => 'Balance Loads',
            self::WalletLoads => 'Wallet Loads',
            self::ShopAccounts => 'Shop Accounts',
            self::Bills => 'Bills',
            self::Repairs => 'Repairs',
            self::NadraVerifications => 'NADRA Verification',
            self::Expenses => 'Expenses',
            self::Settings => 'Settings',
            self::Reports => 'Reports',
            self::Users => 'Users & Roles',
            self::PartyLedger => 'Party Ledger',
            self::SettingsCategories => 'Settings: Categories',
            self::SettingsNetworks => 'Settings: Networks',
            self::SettingsBillConfig => 'Settings: Bill Providers',
            self::SettingsPercentage => 'Settings: Percentage',
        };
    }

    public function routeName(): string
    {
        return match ($this) {
            self::Dashboard => 'dashboard',
            self::Products => 'products.index',
            self::StockIns => 'stock-ins.index',
            self::Customers => 'customers.index',
            self::Sales => 'sales.index',
            self::SimSales => 'sim-sales.index',
            self::Udhaar => 'udhaar.index',
            self::BalanceLoads => 'balance-loads.index',
            self::WalletLoads => 'wallet-loads.index',
            self::ShopAccounts => 'settings.index',
            self::Bills => 'bills.index',
            self::Repairs => 'repairs.index',
            self::NadraVerifications => 'nadra-verifications.index',
            self::Expenses => 'expenses.index',
            self::Settings => 'settings.index',
            self::Reports => 'reports.index',
            self::Users => 'users.index',
            self::PartyLedger => 'party-ledger.index',
            self::SettingsCategories => 'settings.index',
            self::SettingsNetworks => 'settings.index',
            self::SettingsBillConfig => 'settings.index',
            self::SettingsPercentage => 'settings.index',
        };
    }

    /**
     * Screens grouped into logical sections for display — used by both the
     * Super Admin Module Access grid and the shop's own Role "Screen
     * Access" checklist, so the two stay visually consistent and adding a
     * screen only ever means updating this one place.
     *
     * @return array<string, array<self>>
     */
    public static function grouped(): array
    {
        return [
            'Core' => [self::Dashboard, self::Products, self::Customers, self::Reports, self::PartyLedger],
            'Inventory & Sales' => [self::StockIns, self::Sales, self::SimSales],
            'Financial Services' => [self::BalanceLoads, self::WalletLoads, self::Bills, self::Repairs, self::NadraVerifications, self::Udhaar, self::Expenses],
            'Administration' => [self::Users, self::ShopAccounts],
            'Settings' => [self::Settings, self::SettingsCategories, self::SettingsNetworks, self::SettingsBillConfig, self::SettingsPercentage],
        ];
    }
}
