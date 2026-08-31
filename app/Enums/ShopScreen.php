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
        };
    }
}
