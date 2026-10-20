<?php

namespace App\Enums;

enum ShopScreen: string
{
    case Dashboard = 'dashboard';
    case Products = 'products';
    case StockIns = 'stock-ins';
    case Vendors = 'vendors';
    case Customers = 'customers';
    case Sales = 'sales';
    case SalesEditCancel = 'sales-edit-cancel';
    case SalesViewCostPrice = 'sales-view-cost-price';
    case SimSales = 'sim-sales';
    case Udhaar = 'udhaar';
    case BalanceLoads = 'balance-loads';
    case WalletLoads = 'wallet-loads';
    case ShopAccounts = 'shop-accounts';
    case Bills = 'bills';
    case BillsEditCancel = 'bills-edit-cancel';
    case Repairs = 'repairs';
    case RepairsEditCancel = 'repairs-edit-cancel';
    case NadraVerifications = 'nadra-verifications';
    case NadraVerificationsEditCancel = 'nadra-verifications-edit-cancel';
    case Expenses = 'expenses';
    case ExpensesEditCancel = 'expenses-edit-cancel';
    case StockInsEditCancel = 'stock-ins-edit-cancel';
    case WalletLoadsEditCancel = 'wallet-loads-edit-cancel';
    case BalanceLoadsEditCancel = 'balance-loads-edit-cancel';
    case SimSalesEditCancel = 'sim-sales-edit-cancel';
    case Settings = 'settings';
    case Reports = 'reports';
    case Users = 'users';
    case PartyLedger = 'party-ledger';
    case SettingsCategories = 'settings-categories';
    case SettingsNetworks = 'settings-networks';
    case SettingsBillConfig = 'settings-bill-config';
    case SettingsPercentage = 'settings-percentage';
    case SettingsBrands = 'settings-brands';
    case SettingsBranding = 'settings-branding';
    case Services = 'services';
    case ServicesEditCancel = 'services-edit-cancel';
    case SettingsServices = 'settings-services';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Products => 'Products',
            self::StockIns => 'Stock In',
            self::Vendors => 'Vendors',
            self::Customers => 'Customers',
            self::Sales => 'Sales',
            self::SalesEditCancel => 'Sales: Edit / Cancel',
            self::SalesViewCostPrice => 'Sales: View Cost Price',
            self::SimSales => 'SIM Sale',
            self::Udhaar => 'Udhaar',
            self::BalanceLoads => 'Balance Loads',
            self::WalletLoads => 'Wallet Loads',
            self::ShopAccounts => 'Shop Accounts',
            self::Bills => 'Bills',
            self::BillsEditCancel => 'Bills: Edit / Cancel',
            self::Repairs => 'Repairs',
            self::RepairsEditCancel => 'Repairs: Edit / Cancel',
            self::NadraVerifications => 'NADRA Verification',
            self::NadraVerificationsEditCancel => 'NADRA Verification: Edit / Cancel',
            self::Expenses => 'Expenses',
            self::ExpensesEditCancel => 'Expenses: Edit / Cancel',
            self::StockInsEditCancel => 'Stock In: Edit / Cancel',
            self::WalletLoadsEditCancel => 'Wallet Loads: Edit / Cancel',
            self::BalanceLoadsEditCancel => 'Balance Loads: Edit / Cancel',
            self::SimSalesEditCancel => 'SIM Sale: Edit / Cancel',
            self::Settings => 'Settings',
            self::Reports => 'Reports',
            self::Users => 'Users & Roles',
            self::PartyLedger => 'Party Ledger',
            self::SettingsCategories => 'Settings: Categories',
            self::SettingsNetworks => 'Settings: Networks',
            self::SettingsBillConfig => 'Settings: Bill Providers',
            self::SettingsPercentage => 'Settings: Percentage',
            self::SettingsBrands => 'Settings: Brands',
            self::SettingsBranding => 'Settings: Branding',
            self::Services => 'Services',
            self::ServicesEditCancel => 'Services: Edit / Cancel',
            self::SettingsServices => 'Settings: Services',
        };
    }

    public function routeName(): string
    {
        return match ($this) {
            self::Dashboard => 'dashboard',
            self::Products => 'products.index',
            self::StockIns => 'stock-ins.index',
            self::Vendors => 'vendors.index',
            self::Customers => 'customers.index',
            self::Sales => 'sales.index',
            self::SalesEditCancel => 'sales.index',
            self::SalesViewCostPrice => 'sales.index',
            self::SimSales => 'sim-sales.index',
            self::Udhaar => 'udhaar.index',
            self::BalanceLoads => 'balance-loads.index',
            self::WalletLoads => 'wallet-loads.index',
            self::ShopAccounts => 'settings.index',
            self::Bills => 'bills.index',
            self::BillsEditCancel => 'bills.index',
            self::Repairs => 'repairs.index',
            self::RepairsEditCancel => 'repairs.index',
            self::NadraVerifications => 'nadra-verifications.index',
            self::NadraVerificationsEditCancel => 'nadra-verifications.index',
            self::Expenses => 'expenses.index',
            self::ExpensesEditCancel => 'expenses.index',
            self::StockInsEditCancel => 'stock-ins.index',
            self::WalletLoadsEditCancel => 'wallet-loads.index',
            self::BalanceLoadsEditCancel => 'balance-loads.index',
            self::SimSalesEditCancel => 'sim-sales.index',
            self::Settings => 'settings.index',
            self::Reports => 'reports.index',
            self::Users => 'users.index',
            self::PartyLedger => 'party-ledger.index',
            self::SettingsCategories => 'settings.index',
            self::SettingsNetworks => 'settings.index',
            self::SettingsBillConfig => 'settings.index',
            self::SettingsPercentage => 'settings.index',
            self::SettingsBrands => 'settings.index',
            self::SettingsBranding => 'settings.index',
            self::Services => 'services.index',
            self::ServicesEditCancel => 'services.index',
            self::SettingsServices => 'settings.index',
        };
    }

    /**
     * Screens grouped into logical sections for display -- used by both the
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
            'Inventory & Sales' => [self::StockIns, self::StockInsEditCancel, self::Vendors, self::Sales, self::SalesEditCancel, self::SalesViewCostPrice, self::SimSales, self::SimSalesEditCancel],
            'Financial Services' => [self::BalanceLoads, self::BalanceLoadsEditCancel, self::WalletLoads, self::WalletLoadsEditCancel, self::Bills, self::BillsEditCancel, self::Repairs, self::RepairsEditCancel, self::NadraVerifications, self::NadraVerificationsEditCancel, self::Udhaar, self::Expenses, self::ExpensesEditCancel, self::Services, self::ServicesEditCancel],
            'Administration' => [self::Users, self::ShopAccounts],
            'Settings' => [self::Settings, self::SettingsCategories, self::SettingsNetworks, self::SettingsBillConfig, self::SettingsPercentage, self::SettingsBrands, self::SettingsBranding, self::SettingsServices],
        ];
    }
}
