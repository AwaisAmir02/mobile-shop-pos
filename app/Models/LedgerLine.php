<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedgerRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerLine extends Model
{
    use BelongsToShop, ImmutableLedgerRow;

    public $timestamps = false;

    protected $fillable = ['shop_id', 'ledger_entry_id', 'ledger_account_id', 'amount_paise', 'party_type', 'party_id', 'branch_id', 'origin_account_code', 'origin_date'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'origin_date' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }
}
