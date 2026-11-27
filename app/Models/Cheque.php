<?php

namespace App\Models;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
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
            'direction' => ChequeDirection::class,
            'status' => ChequeStatus::class,
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
        return $this->direction === ChequeDirection::Received;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
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
        return $this->status->label();
    }
}
