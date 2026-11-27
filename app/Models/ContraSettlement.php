<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the shop owes a party as a vendor set off against what they owe as a
 * customer (W11), in one record, with no money moving. Only sale bills are
 * set off, never udhaar loans.
 */
class ContraSettlement extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'customer_id', 'vendor_id', 'amount', 'settled_on', 'applied_to', 'note', 'status', 'cancelled_at', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_on' => 'date', 'applied_to' => 'array', 'cancelled_at' => 'datetime', 'status' => RecordStatus::class];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function number(): string
    {
        return 'CT-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === RecordStatus::Cancelled;
    }
}
