<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money a vendor owes the shop back (App\Actions\VendorCredits): a debit note
 * for goods returned, a scheme claim the company settled as credit, or a
 * cheque that paid more than was owed. It is taken off unpaid stock (a
 * stock-in payment row of method vendor_credit) or refunded as money; what is
 * left is `remaining`.
 */
class VendorCredit extends Model
{
    use BelongsToShop;

    public const DEBIT_NOTE = 'debit_note';
    public const CLAIM = 'claim';
    public const CHEQUE = 'cheque';

    protected $fillable = ['shop_id', 'vendor_id', 'source', 'source_id', 'amount', 'remaining', 'entry_date', 'applied_to', 'note', 'status', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'remaining' => 'decimal:2', 'entry_date' => 'date', 'applied_to' => 'array'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(VendorCreditRefund::class);
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::DEBIT_NOTE => 'Debit note DN-'.str_pad((string) $this->source_id, 6, '0', STR_PAD_LEFT),
            self::CLAIM => 'Scheme claim CL-'.str_pad((string) $this->source_id, 6, '0', STR_PAD_LEFT),
            default => 'Cheque paid more than owed',
        };
    }
}
