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
    public const TAX_DEDUCTED = '1170';
    public const RECEIVABLE = '1200';
    public const EMPLOYEE_ADVANCES = '1250';
    public const INVENTORY = '1300';
    /** A traded-in phone: the sale's Trade-in payment and the buyback's payment meet here and net to zero. */
    public const TRADE_IN = '1350';
    public const EXCHANGE_CLEARING = '1360';
    public const VENDOR_CREDITS = '1370';
    public const CONTRA_CLEARING = '1380';
    public const COURIER_CLEARING = '1390';
    public const CLEARING = '1400';
    public const CANCELLED_UNCONFIRMED = '1450';
    public const PAYABLE = '2000';
    public const WITHHOLDINGS = '2100';
    public const CUSTOMER_CREDIT = '2200';
    public const CUSTOMER_DEPOSITS = '2210';
    public const GIFT_VOUCHERS = '2220';
    public const CAPITAL = '3000';
    public const DRAWINGS = '3100';
    public const OPENING = '3200';
    public const PRIOR_PERIOD = '3300';
    public const SALES = '4000';
    public const SALES_RETURNS = '4050';
    public const DISCOUNTS = '4100';
    public const SERVICE_INCOME = '4200';
    public const SERVICE_SALES = '4210';
    public const REPAIR_INCOME = '4300';
    public const EXPIRED_CREDIT = '4400';
    public const SCHEME_INCOME = '4450';
    public const DELIVERY_INCOME = '4460';
    public const FORFEITED_DEPOSITS = '4470';
    public const COGS = '5000';
    public const WRITE_OFFS = '5100';
    public const ADJUSTMENTS = '5200';
    public const SALARIES = '6000';
    public const EXPENSES = '6100';
    public const COURIER_CHARGES = '6150';

    /** @var array<string, array{0: string, 1: string}> code => [name, type] */
    public const ACCOUNTS = [
        self::CASH => ['Cash in Hand', LedgerAccount::ASSET],
        self::BANK => ['Bank & Wallets', LedgerAccount::ASSET],
        self::NOT_CLASSIFIED => ['Receipts Not Classified', LedgerAccount::ASSET],
        self::TAX_DEDUCTED => ['Tax Deducted by Couriers', LedgerAccount::ASSET],
        self::RECEIVABLE => ['Accounts Receivable', LedgerAccount::ASSET],
        self::EMPLOYEE_ADVANCES => ['Employee Advances', LedgerAccount::ASSET],
        self::INVENTORY => ['Inventory', LedgerAccount::ASSET],
        self::TRADE_IN => ['Trade-in Clearing', LedgerAccount::ASSET],
        self::EXCHANGE_CLEARING => ['Exchange Clearing', LedgerAccount::ASSET],
        self::VENDOR_CREDITS => ['Vendor Credits (debit notes & claims)', LedgerAccount::ASSET],
        self::CONTRA_CLEARING => ['Contra Clearing', LedgerAccount::ASSET],
        self::COURIER_CLEARING => ['Courier COD Clearing', LedgerAccount::ASSET],
        self::CLEARING => ['Agent Float & Clearing', LedgerAccount::ASSET],
        self::CANCELLED_UNCONFIRMED => ['Cancelled, Cash Unconfirmed', LedgerAccount::ASSET],
        self::PAYABLE => ['Accounts Payable', LedgerAccount::LIABILITY],
        self::WITHHOLDINGS => ['Withholdings Payable', LedgerAccount::LIABILITY],
        self::CUSTOMER_CREDIT => ['Customer Store Credit', LedgerAccount::LIABILITY],
        self::CUSTOMER_DEPOSITS => ['Customer Deposits', LedgerAccount::LIABILITY],
        self::GIFT_VOUCHERS => ['Gift Vouchers Outstanding', LedgerAccount::LIABILITY],
        self::CAPITAL => ["Owner's Capital", LedgerAccount::EQUITY],
        self::DRAWINGS => ["Owner's Drawings", LedgerAccount::EQUITY],
        self::OPENING => ['Opening Balances', LedgerAccount::EQUITY],
        self::PRIOR_PERIOD => ['Prior Period Adjustments', LedgerAccount::EQUITY],
        self::SALES => ['Sales', LedgerAccount::INCOME],
        self::SALES_RETURNS => ['Sales Returns', LedgerAccount::INCOME],
        self::DISCOUNTS => ['Discounts Given', LedgerAccount::INCOME],
        self::SERVICE_INCOME => ['Service & Commission Income', LedgerAccount::INCOME],
        self::SERVICE_SALES => ['Service & Membership Sales', LedgerAccount::INCOME],
        self::REPAIR_INCOME => ['Repair Income', LedgerAccount::INCOME],
        self::EXPIRED_CREDIT => ['Expired Store Credit', LedgerAccount::INCOME],
        self::SCHEME_INCOME => ['Scheme Claims Received', LedgerAccount::INCOME],
        self::DELIVERY_INCOME => ['Delivery Charges Income', LedgerAccount::INCOME],
        self::FORFEITED_DEPOSITS => ['Forfeited Deposits', LedgerAccount::INCOME],
        self::COGS => ['Cost of Goods Sold', LedgerAccount::EXPENSE],
        self::WRITE_OFFS => ['Inventory Write-offs', LedgerAccount::EXPENSE],
        self::ADJUSTMENTS => ['Inventory Adjustments', LedgerAccount::EXPENSE],
        self::SALARIES => ['Salaries & Wages', LedgerAccount::EXPENSE],
        self::EXPENSES => ['General Expenses', LedgerAccount::EXPENSE],
        self::COURIER_CHARGES => ['Courier & Delivery Charges', LedgerAccount::EXPENSE],
    ];

    /**
     * Accounts added to the chart after shops had already started their
     * ledger: created the first time one is posted to. Any other missing
     * account is a fault and fails the posting.
     */
    public const ADDED_LATER = [self::TRADE_IN, self::SALES_RETURNS, self::CUSTOMER_CREDIT, self::EXPIRED_CREDIT, self::EXCHANGE_CLEARING, self::VENDOR_CREDITS, self::CONTRA_CLEARING, self::SCHEME_INCOME, ...self::ON_FIRST_USE];

    /**
     * Accounts only some shops ever use: never in a new chart, only created when
     * first posted to (LedgerPoster::bankAccount), so a shop that doesn't use the
     * tool keeps a chart and Trial Balance without them.
     *
     * - Online P1 (delivery charges, courier payouts). Tax Deducted by Couriers is
     *   the withholding a courier takes from a payout, an asset claimed against
     *   income tax; the accountant should confirm its treatment.
     * - Services group (service sales, deposits, gift vouchers, kept deposits).
     *
     * A chart created before 2026-11-27 already has the four Online accounts (the
     * check below didn't match then); they stay, at zero, and nothing removes them.
     */
    public const ON_FIRST_USE = [
        self::TAX_DEDUCTED, self::COURIER_CLEARING, self::DELIVERY_INCOME, self::COURIER_CHARGES,
        self::SERVICE_SALES, self::CUSTOMER_DEPOSITS, self::GIFT_VOUCHERS, self::FORFEITED_DEPOSITS,
    ];

    public static function ensureFor(Shop $shop): void
    {
        foreach (self::ACCOUNTS as $code => [$name, $type]) {
            // PHP stores numeric-string array keys ('1170') as ints, and the codes are strings.
            $code = (string) $code;

            if (in_array($code, self::ON_FIRST_USE, true)) {
                continue;
            }

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

    /** Where money moved, as far as it was recorded. */
    public static function paidThrough(mixed $method, ?int $shopAccountId, string $unrecorded = self::NOT_CLASSIFIED): string
    {
        $method = $method instanceof \BackedEnum ? $method->value : $method;

        return match (true) {
            $shopAccountId !== null => self::bankCode($shopAccountId),
            $method === 'cash' => self::CASH,
            $method === 'account' => self::BANK,
            $method === 'trade_in' => self::TRADE_IN,
            $method === 'store_credit' => self::CUSTOMER_CREDIT,
            $method === 'exchange' => self::EXCHANGE_CLEARING,
            $method === 'vendor_credit' => self::VENDOR_CREDITS,
            $method === 'contra' => self::CONTRA_CLEARING,
            $method === 'courier' => self::COURIER_CLEARING,
            $method === 'gift_voucher' => self::GIFT_VOUCHERS,
            $method === 'deposit' => self::CUSTOMER_DEPOSITS,
            default => $unrecorded,
        };
    }

    public static function bankCode(int $shopAccountId): string
    {
        return self::BANK.'-'.$shopAccountId;
    }

    /** A bank sub-account is "1100-7": ShopAccount 7 under Bank & Wallets. */
    public static function parentCode(string $code): string
    {
        return explode('-', $code)[0];
    }
}
