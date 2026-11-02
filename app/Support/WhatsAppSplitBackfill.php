<?php

namespace App\Support;

/**
 * The exact SQL run in production (phpMyAdmin) to split the single
 * "whatsapp" feature into whatsapp_ordering and whatsapp_messaging. The
 * migration and the tests use these same strings, so what is tested is
 * what gets pasted. Every statement is safe to run more than once.
 */
class WhatsAppSplitBackfill
{
    /**
     * A shop with WhatsApp on gets both halves on. Only shops where neither
     * half is set yet, so a re-run can't undo an admin switching one off.
     */
    public const SHOP_COLUMNS_SQL = <<<'SQL'
        UPDATE shops
        SET whatsapp_ordering_enabled = 1, whatsapp_messaging_enabled = 1
        WHERE whatsapp_enabled = 1 AND whatsapp_ordering_enabled = 0 AND whatsapp_messaging_enabled = 0
        SQL;

    /** "whatsapp": x in a plan's feature_flags becomes both new keys = x. */
    public const PLAN_FLAGS_SQL = <<<'SQL'
        UPDATE plans
        SET feature_flags = JSON_REMOVE(
            JSON_SET(feature_flags, '$.whatsapp_ordering', JSON_EXTRACT(feature_flags, '$.whatsapp'), '$.whatsapp_messaging', JSON_EXTRACT(feature_flags, '$.whatsapp')),
            '$.whatsapp'
        )
        WHERE JSON_CONTAINS_PATH(feature_flags, 'one', '$.whatsapp')
        SQL;

    /** The same for an admin's per-shop feature overrides. */
    public const SHOP_OVERRIDES_SQL = <<<'SQL'
        UPDATE shops
        SET feature_overrides = JSON_REMOVE(
            JSON_SET(feature_overrides, '$.whatsapp_ordering', JSON_EXTRACT(feature_overrides, '$.whatsapp'), '$.whatsapp_messaging', JSON_EXTRACT(feature_overrides, '$.whatsapp')),
            '$.whatsapp'
        )
        WHERE JSON_CONTAINS_PATH(feature_overrides, 'one', '$.whatsapp')
        SQL;

    /** Tables whose feature unlocks move from feature_key to the feature_keys list. */
    public const ADDON_TABLES = ['addons', 'shop_addons'];

    /** A "whatsapp" unlock becomes a bundle of both halves; any other key a list of one. */
    public static function addonKeysSql(string $table): string
    {
        return <<<SQL
            UPDATE {$table}
            SET feature_keys = CASE
                WHEN feature_key = 'whatsapp' THEN JSON_ARRAY('whatsapp_ordering', 'whatsapp_messaging')
                ELSE JSON_ARRAY(feature_key)
            END
            WHERE feature_key IS NOT NULL AND feature_keys IS NULL
            SQL;
    }

    /** Rollback: the old flag is on if either half is. */
    public const REVERSE_SHOP_COLUMNS_SQL = 'UPDATE shops SET whatsapp_enabled = (whatsapp_ordering_enabled OR whatsapp_messaging_enabled)';

    /** Rollback: both halves back to one "whatsapp" key, on if either half was. */
    public static function reverseFlagsSql(string $table, string $column): string
    {
        return <<<SQL
            UPDATE {$table}
            SET {$column} = JSON_REMOVE(
                JSON_SET({$column}, '$.whatsapp', CAST(IF(JSON_EXTRACT({$column}, '$.whatsapp_ordering') = true OR JSON_EXTRACT({$column}, '$.whatsapp_messaging') = true, 'true', 'false') AS JSON)),
                '$.whatsapp_ordering', '$.whatsapp_messaging'
            )
            WHERE JSON_CONTAINS_PATH({$column}, 'one', '$.whatsapp_ordering', '$.whatsapp_messaging')
            SQL;
    }

    /** Rollback: an unlock with either half becomes "whatsapp"; any other the first key of its list. */
    public static function reverseAddonKeysSql(string $table): array
    {
        return [
            "UPDATE {$table} SET feature_key = 'whatsapp' WHERE JSON_CONTAINS(feature_keys, '\"whatsapp_ordering\"') OR JSON_CONTAINS(feature_keys, '\"whatsapp_messaging\"')",
            "UPDATE {$table} SET feature_key = JSON_UNQUOTE(JSON_EXTRACT(feature_keys, '$[0]')) WHERE feature_key IS NULL AND feature_keys IS NOT NULL",
        ];
    }

    /** Everything still in the old shape. Read-only. Should return no rows. */
    public static function reconcileSql(): string
    {
        return collect([
            "SELECT 'shops.whatsapp_enabled' AS what, COUNT(*) AS rows_left FROM shops WHERE whatsapp_enabled = 1 AND whatsapp_ordering_enabled = 0 AND whatsapp_messaging_enabled = 0 HAVING COUNT(*) > 0",
            "SELECT 'plans.feature_flags' AS what, COUNT(*) AS rows_left FROM plans WHERE JSON_CONTAINS_PATH(feature_flags, 'one', '$.whatsapp') HAVING COUNT(*) > 0",
            "SELECT 'shops.feature_overrides' AS what, COUNT(*) AS rows_left FROM shops WHERE JSON_CONTAINS_PATH(feature_overrides, 'one', '$.whatsapp') HAVING COUNT(*) > 0",
            ...array_map(fn (string $table) => "SELECT '{$table}.feature_keys' AS what, COUNT(*) AS rows_left FROM {$table} WHERE feature_key IS NOT NULL AND feature_keys IS NULL HAVING COUNT(*) > 0", self::ADDON_TABLES),
        ])->implode("\nUNION ALL\n");
    }
}
