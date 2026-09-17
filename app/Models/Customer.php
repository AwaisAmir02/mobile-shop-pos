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

    public function walletLoads(): HasMany
    {
        return $this->hasMany(WalletLoad::class)->latest();
    }

    public function balanceLoads(): HasMany
    {
        return $this->hasMany(BalanceLoad::class)->latest();
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class)->latest();
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class)->latest();
    }

    public function nadraVerifications(): HasMany
    {
        return $this->hasMany(NadraVerification::class)->latest();
    }

    public function simSales(): HasMany
    {
        return $this->hasMany(SimSale::class)->latest();
    }

    public function udhaarBalance(): float
    {
        return $this->udhaarTransactions
            ->sum(fn (UdhaarTransaction $transaction) => $transaction->signedAmount());
    }

    /**
     * Outstanding balance from Sales specifically — kept entirely separate
     * from the Udhaar balance above, since they are two different kinds of
     * money owed and must never be combined or netted together.
     */
    public function salesOutstandingBalance(): float
    {
        return Sale::where('customer_id', $this->id)
            ->withSum('payments', 'amount')
            ->get()
            ->sum(fn (Sale $sale) => $sale->amountDue());
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
        return $this->udhaarTransactions()->exists()
            || $this->sales()->exists()
            || $this->walletLoads()->exists()
            || $this->balanceLoads()->exists()
            || $this->billPayments()->exists()
            || $this->repairs()->exists()
            || $this->nadraVerifications()->exists()
            || $this->simSales()->exists();
    }
}
