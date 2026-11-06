<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Documents waiting to post. Not shop-scoped: the sweep works across shops. */
class LedgerOutbox extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ledger_outbox';

    protected $fillable = ['shop_id', 'source_type', 'source_id', 'attempts', 'last_error', 'created_at'];

    /** One waiting row per document, however many times it changes before posting. */
    public static function mark(int $shopId, string $key, int $id): void
    {
        static::query()->insertOrIgnore(['shop_id' => $shopId, 'source_type' => $key, 'source_id' => $id, 'attempts' => 0, 'created_at' => now()]);
    }
}
