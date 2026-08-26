<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'address',
    ];

    public function udhaarTransactions(): HasMany
    {
        return $this->hasMany(UdhaarTransaction::class)->orderBy('transaction_date')->orderBy('id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class)->latest();
    }

    public function udhaarBalance(): float
    {
        return $this->udhaarTransactions
            ->sum(fn (UdhaarTransaction $transaction) => $transaction->signedAmount());
    }

    public function udhaarStatus(): string
    {
        $balance = $this->udhaarBalance();

        return match (true) {
            $balance > 0 => 'due',
            $balance < 0 => 'advance',
            default => 'settled',
        };
    }

    /**
     * Whether this customer has any financial history (sales, udhaar, etc.)
     * that should block deletion. Extended by later modules as they add
     * their own customer-linked records.
     */
    public function hasFinancialHistory(): bool
    {
        return $this->udhaarTransactions()->exists() || $this->sales()->exists();
    }
}
