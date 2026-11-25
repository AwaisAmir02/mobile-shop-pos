<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cheque (W6), usually post-dated: received from a customer or issued to a
 * vendor. It settles nothing until it clears, as at the bank; a bounce after
 * clearing reverses exactly what it settled (App\Actions\Cheques).
 */
class Cheque extends Model
{
    use BelongsToShop;

    public const RECEIVED = 'received';
    public const ISSUED = 'issued';

    public const PENDING = 'pending';
    public const DEPOSITED = 'deposited';
    public const CLEARED = 'cleared';
    public const BOUNCED = 'bounced';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'shop_id', 'direction', 'customer_id', 'vendor_id', 'cheque_number', 'bank', 'amount', 'cheque_date', 'received_on', 'status',
        'shop_account_id', 'deposited_on', 'cleared_on', 'bounced_on', 'bounce_reason', 'customer_receipt_id', 'applied_to', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cheque_date' => 'date',
            'received_on' => 'date',
            'deposited_on' => 'date',
            'cleared_on' => 'date',
            'bounced_on' => 'date',
            'applied_to' => 'array',
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

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class)->withoutGlobalScopes();
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function number(): string
    {
        return 'CHQ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isReceived(): bool
    {
        return $this->direction === self::RECEIVED;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::DEPOSITED], true);
    }

    public function partyName(): string
    {
        return $this->isReceived() ? ($this->customer?->displayName() ?? '-') : ($this->vendor?->name ?? '-');
    }

    public function label(): string
    {
        return 'Cheque '.$this->cheque_number.' ('.$this->bank.')';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'In hand',
            self::DEPOSITED => 'Deposited',
            self::CLEARED => 'Cleared',
            self::BOUNCED => 'Bounced',
            default => 'Cancelled',
        };
    }
}
