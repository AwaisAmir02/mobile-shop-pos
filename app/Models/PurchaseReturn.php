<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods sent back to a vendor (W10), and the debit note for them. */
class PurchaseReturn extends Model
{
    use BelongsToShop;

    public const REASONS = ['expired' => 'Expired', 'damaged' => 'Damaged', 'wrong_item' => 'Wrong item', 'excess' => 'Excess stock', 'other' => 'Other'];

    protected $fillable = ['shop_id', 'vendor_id', 'return_date', 'reason', 'note', 'total_value', 'status', 'cancelled_at', 'cancelled_by', 'user_id'];

    protected function casts(): array
    {
        return ['return_date' => 'date', 'total_value' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credit(): ?VendorCredit
    {
        return VendorCredit::query()->where('source', VendorCredit::DEBIT_NOTE)->where('source_id', $this->id)->first();
    }

    public function number(): string
    {
        return 'DN-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst((string) $this->reason);
    }
}
