<?php

namespace App\Support;

/**
 * The exact SQL run in production (phpMyAdmin) for multi-branch Phase 1.
 * The migration and the tests use these same strings, so what is tested is
 * what gets pasted. Every statement is safe to run more than once.
 */
class BranchBackfill
{
    /** Tables whose rows are recorded at a branch. Child rows (sale items, payments) follow their parent. */
    public const TRANSACTION_TABLES = [
        'sales',
        'stock_ins',
        'udhaar_transactions',
        'expenses',
        'balance_loads',
        'wallet_loads',
        'bill_payments',
        'repairs',
        'nadra_verifications',
        'sim_sales',
        'service_payments',
        'whatsapp_orders',
        'party_ledger_entries',
    ];

    /** One "Main" branch for every shop that has none yet. */
    public const MAIN_BRANCHES_SQL = <<<'SQL'
        INSERT INTO branches (shop_id, name, is_main, created_at, updated_at)
        SELECT s.id, 'Main', 1, NOW(), NOW()
        FROM shops s
        WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.shop_id = s.id AND b.is_main = 1)
        SQL;

    /**
     * A plan with no "branches" key would read as unlimited branches, so
     * every existing plan is given exactly 1. A plan that already has the
     * key (even an explicit null) is left alone.
     */
    public const PLAN_LIMITS_SQL = <<<'SQL'
        UPDATE plans
        SET limits = JSON_SET(COALESCE(limits, JSON_OBJECT()), '$.branches', 1)
        WHERE limits IS NULL OR JSON_CONTAINS_PATH(limits, 'one', '$.branches') = 0
        SQL;

    /** Tags every untagged row of $table with its shop's Main branch. */
    public static function tagSql(string $table): string
    {
        return <<<SQL
            UPDATE {$table} t
            JOIN branches b ON b.shop_id = t.shop_id AND b.is_main = 1
            SET t.branch_id = b.id
            WHERE t.branch_id IS NULL
            SQL;
    }

    /**
     * Every transactional row still without a branch. Read-only. Should
     * return no rows.
     */
    public static function reconcileSql(): string
    {
        return collect(self::TRANSACTION_TABLES)
            ->map(fn (string $table) => "SELECT '{$table}' AS table_name, COUNT(*) AS untagged FROM {$table} WHERE branch_id IS NULL HAVING COUNT(*) > 0")
            ->implode("\nUNION ALL\n");
    }
}
