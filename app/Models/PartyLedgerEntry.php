<?php

namespace App\Models;

use App\Enums\PartyLedgerDirection;
use App\Enums\PartyLedgerEntryKind;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually entered opening balance or adjustment on a customer's or a
 * vendor's Party Ledger statement — exactly one of the two per entry. These
 * add up to their own "Opening & adjustments" balance and are never merged
 * into the balances that come straight from Sales, Udhaar, or Stock-In.
 * Entries are never edited or deleted — a wrong one is cancelled with a
 * reason.
 */
class PartyLedgerEntry extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    protected $fillable = [
        'shop_id',
        'customer_id',
        'vendor_id',
        'user_id',
        'kind',
        'direction',
        'amount',
        'entry_date',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected static function booted(): void
    {
        static::saving(function (PartyLedgerEntry $entry) {
            if (($entry->customer_id === null) === ($entry->vendor_id === null)) {
                throw new \LogicException('A Party Ledger entry belongs to exactly one customer or one vendor.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => PartyLedgerEntryKind::class,
            'direction' => PartyLedgerDirection::class,
            'amount' => 'decimal:2',
            'entry_date' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
