<?php

namespace App\Support;

/**
 * The exact SQL run in production (phpMyAdmin) for stock_in_payments. The
 * migration and the tests use these same strings, so what is tested is what
 * gets pasted.
 */
class StockInPaymentBackfill
{
    /**
     * Adds one "brought forward" row per Stock-In for whatever part of its
     * amount_paid has no payment row yet, dated at its stock_date. Safe to
     * run more than once, and before or after the code upload: it only ever
     * tops up the missing difference.
     */
    public const BACKFILL_SQL = <<<'SQL'
        INSERT INTO stock_in_payments (shop_id, stock_in_id, user_id, amount, payment_date, source, created_at, updated_at)
        SELECT s.shop_id, s.id, NULL, s.amount_paid - COALESCE(p.paid, 0), s.stock_date, 'backfill', NOW(), NOW()
        FROM stock_ins s
        LEFT JOIN (SELECT stock_in_id, SUM(amount) AS paid FROM stock_in_payments GROUP BY stock_in_id) p ON p.stock_in_id = s.id
        WHERE s.amount_paid > COALESCE(p.paid, 0)
        SQL;

    /**
     * Every Stock-In whose payment rows don't add up to its amount_paid.
     * Read-only. Should return no rows.
     */
    public const RECONCILE_SQL = <<<'SQL'
        SELECT s.id, s.shop_id, s.amount_paid, COALESCE(p.paid, 0) AS payment_rows_total
        FROM stock_ins s
        LEFT JOIN (SELECT stock_in_id, SUM(amount) AS paid FROM stock_in_payments GROUP BY stock_in_id) p ON p.stock_in_id = s.id
        WHERE ROUND(s.amount_paid, 2) <> ROUND(COALESCE(p.paid, 0), 2)
        SQL;
}
