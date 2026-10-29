<?php

namespace App\Models;

use App\Enums\PartyLedgerDirection;
use App\Enums\PartyLedgerEntryKind;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually entered opening balance or adjustment on a customer's Party
 * Ledger statement. These add up to their own "Opening & adjustments"
 * balance and are never merged into the Sales or Udhaar balances, which
 * always come straight from their own modules. Entries are never edited
 * or deleted — a wrong one is cancelled with a reason.
 */
class PartyLedgerEntry extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'customer_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signedAmount(): float
    {
        return $this->direction->sign() * (float) $this->amount;
    }
}
