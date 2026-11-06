<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a document looked like when the ledger started. Those lines were never
 * posted, since the opening balances already cover them; only later changes to
 * the document post. Not shop-scoped: the poster works across shops.
 */
class LedgerBaseline extends Model
{
    public $timestamps = false;

    protected $fillable = ['shop_id', 'epoch', 'source_type', 'source_id', 'lines'];

    protected function casts(): array
    {
        return ['lines' => 'array'];
    }
}
