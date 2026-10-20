<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\UdhaarTransactionType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class UdhaarTransaction extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'customer_id',
        'user_id',
        'type',
        'amount',
        'transaction_date',
        'note',
        'reverses_transaction_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => UdhaarTransactionType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
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

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function signedAmount(): float
    {
        return $this->type->sign() * (float) $this->amount;
    }

    public function isReversed(): bool
    {
        return ! $this->type->isReversal() && $this->reversal()->exists();
    }

    /**
     * Edit, Cancel, and Reverse are mutually exclusive actions on the same
     * row: once reversed, cancelling is redundant (the effect is already
     * offset); once cancelled, reversing or editing is meaningless (the row
     * no longer counts toward anything). A reversal row is itself never
     * actionable — it exists only to correct its original.
     */
    public function isActionable(): bool
    {
        return ! $this->type->isReversal() && ! $this->isReversed() && ! $this->isCancelled();
    }

    /**
     * Net udhaar balance per customer_id: positive = customer owes the shop,
     * negative = customer has overpaid (advance), zero = settled.
     *
     * @return Collection<int, float>
     */
    public static function balancesByCustomer(): Collection
    {
        return static::query()
            ->selectRaw("customer_id, SUM(
                CASE type
                    WHEN 'given' THEN amount
                    WHEN 'repayment_reversal' THEN amount
                    WHEN 'repayment' THEN -amount
                    WHEN 'given_reversal' THEN -amount
                    ELSE 0
                END
            ) as balance")
            ->groupBy('customer_id')
            ->pluck('balance', 'customer_id')
            ->map(fn ($balance) => (float) $balance);
    }
}
