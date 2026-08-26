<?php

namespace App\Enums;

enum ShopScreen: string
{
    case Dashboard = 'dashboard';
    case Products = 'products';
    case Sales = 'sales';
    case BalanceLoads = 'balance-loads';
    case WalletLoads = 'wallet-loads';
    case Expenses = 'expenses';
    case SimManagement = 'sim-management';
    case Settings = 'settings';
    case Reports = 'reports';
    case Users = 'users';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Products => 'Products',
            self::Sales => 'Sales',
            self::BalanceLoads => 'Balance Loads',
            self::WalletLoads => 'Wallet Loads',
            self::Expenses => 'Expenses',
            self::SimManagement => 'SIM Management',
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
            self::Sales => 'sales.index',
            self::BalanceLoads => 'balance-loads.index',
            self::WalletLoads => 'wallet-loads.index',
            self::Expenses => 'expenses.index',
            self::SimManagement => 'sim-management.index',
            self::Settings => 'settings.index',
            self::Reports => 'reports.index',
            self::Users => 'users.index',
        };
    }
}
