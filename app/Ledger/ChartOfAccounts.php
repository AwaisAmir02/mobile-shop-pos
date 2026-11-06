<?php

namespace App\Ledger;

use App\Models\LedgerAccount;
use App\Models\Shop;
use RuntimeException;

/**
 * The fixed system accounts. A shop can't edit these in v1. Customers, vendors
 * and employees are a party on the line, and branch is a column on the line, so
 * neither multiplies the chart. Sales tax is deliberately absent: the app holds
 * no tax data. This chart needs an accountant's review before real shops use it.
 */
class ChartOfAccounts
{
    public const CASH = '1000';
    public const BANK = '1100';
    public const NOT_CLASSIFIED = '1150';
    public const RECEIVABLE = '1200';
    public const EMPLOYEE_ADVANCES = '1250';
    public const INVENTORY = '1300';
    public const CLEARING = '1400';
    public const CANCELLED_UNCONFIRMED = '1450';
    public const PAYABLE = '2000';
    public const WITHHOLDINGS = '2100';
    public const CAPITAL = '3000';
    public const DRAWINGS = '3100';
    public const OPENING = '3200';
    public const PRIOR_PERIOD = '3300';
    public const SALES = '4000';
    public const DISCOUNTS = '4100';
    public const SERVICE_INCOME = '4200';
    public const REPAIR_INCOME = '4300';
    public const COGS = '5000';
    public const WRITE_OFFS = '5100';
    public const ADJUSTMENTS = '5200';
    public const SALARIES = '6000';
    public const EXPENSES = '6100';

    /** @var array<string, array{0: string, 1: string}> code => [name, type] */
    public const ACCOUNTS = [
        self::CASH => ['Cash in Hand', LedgerAccount::ASSET],
        self::BANK => ['Bank & Wallets', LedgerAccount::ASSET],
        self::NOT_CLASSIFIED => ['Receipts Not Classified', LedgerAccount::ASSET],
        self::RECEIVABLE => ['Accounts Receivable', LedgerAccount::ASSET],
        self::EMPLOYEE_ADVANCES => ['Employee Advances', LedgerAccount::ASSET],
        self::INVENTORY => ['Inventory', LedgerAccount::ASSET],
        self::CLEARING => ['Agent Float & Clearing', LedgerAccount::ASSET],
        self::CANCELLED_UNCONFIRMED => ['Cancelled, Cash Unconfirmed', LedgerAccount::ASSET],
        self::PAYABLE => ['Accounts Payable', LedgerAccount::LIABILITY],
        self::WITHHOLDINGS => ['Withholdings Payable', LedgerAccount::LIABILITY],
        self::CAPITAL => ["Owner's Capital", LedgerAccount::EQUITY],
        self::DRAWINGS => ["Owner's Drawings", LedgerAccount::EQUITY],
        self::OPENING => ['Opening Balances', LedgerAccount::EQUITY],
        self::PRIOR_PERIOD => ['Prior Period Adjustments', LedgerAccount::EQUITY],
        self::SALES => ['Sales', LedgerAccount::INCOME],
        self::DISCOUNTS => ['Discounts Given', LedgerAccount::INCOME],
        self::SERVICE_INCOME => ['Service & Commission Income', LedgerAccount::INCOME],
        self::REPAIR_INCOME => ['Repair Income', LedgerAccount::INCOME],
        self::COGS => ['Cost of Goods Sold', LedgerAccount::EXPENSE],
        self::WRITE_OFFS => ['Inventory Write-offs', LedgerAccount::EXPENSE],
        self::ADJUSTMENTS => ['Inventory Adjustments', LedgerAccount::EXPENSE],
        self::SALARIES => ['Salaries & Wages', LedgerAccount::EXPENSE],
        self::EXPENSES => ['General Expenses', LedgerAccount::EXPENSE],
    ];

    public static function ensureFor(Shop $shop): void
    {
        foreach (self::ACCOUNTS as $code => [$name, $type]) {
            LedgerAccount::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'code' => $code], ['name' => $name, 'type' => $type]);
        }
    }

    /** @return array<string, int> code => id, for a shop whose chart exists */
    public static function idsFor(int $shopId): array
    {
        $ids = LedgerAccount::withoutGlobalScopes()->where('shop_id', $shopId)->pluck('id', 'code')->all();

        if ($ids === []) {
            throw new RuntimeException("Shop {$shopId} has no chart of accounts.");
        }

        return $ids;
    }

    public static function isProfitAndLoss(string $code): bool
    {
        $type = self::ACCOUNTS[$code][1] ?? self::ACCOUNTS[self::parentCode($code)][1] ?? LedgerAccount::ASSET;

        return in_array($type, [LedgerAccount::INCOME, LedgerAccount::EXPENSE], true);
    }

    /** A bank sub-account is "1100-7": ShopAccount 7 under Bank & Wallets. */
    public static function parentCode(string $code): string
    {
        return explode('-', $code)[0];
    }
}
