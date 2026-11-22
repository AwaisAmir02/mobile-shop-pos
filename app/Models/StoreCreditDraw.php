<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of one issue of store credit a spend, cash-out or expiry used. */
class StoreCreditDraw extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'entry_id', 'issue_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(StoreCreditEntry::class, 'issue_id')->withoutGlobalScopes();
    }
}
