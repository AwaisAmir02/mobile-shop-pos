<?php

namespace App\Enums;

enum ShopScreen: string
{
    case Dashboard = 'dashboard';
    case Products = 'products';
    case StockIns = 'stock-ins';
    case Customers = 'customers';
    case Sales = 'sales';
    case Udhaar = 'udhaar';
    case BalanceLoads = 'balance-loads';
    case WalletLoads = 'wallet-loads';
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
            self::Udhaar => 'Udhaar',
            self::BalanceLoads => 'Balance Loads',
            self::WalletLoads => 'Wallet Loads',
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
            self::Udhaar => 'udhaar.index',
            self::BalanceLoads => 'balance-loads.index',
            self::WalletLoads => 'wallet-loads.index',
            self::Expenses => 'expenses.index',
            self::Settings => 'settings.index',
            self::Reports => 'reports.index',
            self::Users => 'users.index',
        };
    }
}
