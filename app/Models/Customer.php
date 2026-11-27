<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\BranchScope;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer belongs to the whole shop, not a branch: every relation and
 * balance here ignores BranchScope, so a branch-restricted user and the
 * owner always see the same history and the same amounts owed.
 */
class Customer extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if ($customer->isDirty('phone') || ! $customer->exists) {
                $customer->phone_normalized = PhoneNumber::normalize($customer->phone);
            }
        });
    }

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'member_no',
        'checkin_token',
        'address',
        // Wholesale businesses (W2, W3, W5); all optional.
        'business_name',
        'contact_person',
        'phone_2',
        'area',
        'ntn',
        'strn',
        'cnic',
        'price_tier',
        'credit_limit',
        'payment_terms_days',
        // Wholesale P2: the route this customer is on, their place in its visiting order, and their vendor record.
        'route_id',
        'route_sequence',
        'vendor_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            // Null is the default level, the trade price (PriceTiers::forCustomer).
            'price_tier' => \App\Enums\PriceTier::class,
        ];
    }

    /** "Bilal General Store (Bilal)" for a business, else just the name. */
    public function displayName(): string
    {
        return filled($this->business_name) ? $this->business_name.' ('.$this->name.')' : $this->name;
    }

    /** "NTN 1234567-8 · STRN …", or null when none is set. */
    public function taxNumbersLine(): ?string
    {
        $parts = array_filter(['NTN' => $this->ntn, 'STRN' => $this->strn, 'CNIC' => $this->cnic], fn ($value) => filled($value));

        return $parts === [] ? null : collect($parts)->map(fn ($value, $label) => $label.' '.$value)->implode(' · ');
    }

    /** The delivery route they are on, and visited in route_sequence order (W8). Not route(), which reads like the URL helper. */
    public function deliveryRoute(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    /** The same party as one of the shop's vendors (W11). */
    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class)->latest('id');
    }

    protected $hidden = ['ledger_token'];

    public function udhaarTransactions(): HasMany
    {
        return $this->hasMany(UdhaarTransaction::class)->withoutGlobalScope(BranchScope::class)->orderBy('transaction_date')->orderBy('id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function walletLoads(): HasMany
    {
        return $this->hasMany(WalletLoad::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function balanceLoads(): HasMany
    {
        return $this->hasMany(BalanceLoad::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function nadraVerifications(): HasMany
    {
        return $this->hasMany(NadraVerification::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function simSales(): HasMany
    {
        return $this->hasMany(SimSale::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    public function servicePayments(): HasMany
    {
        return $this->hasMany(ServicePayment::class)->withoutGlobalScope(BranchScope::class)->latest();
    }

    /**
     * The private link to this customer's own read-only ledger, made the first
     * time it's needed. Anyone with the link can see the balance, so it's long
     * and random, and the shop can replace it (rotateLedgerToken).
     */
    public function ledgerUrl(): string
    {
        if ($this->ledger_token === null) {
            $this->forceFill(['ledger_token' => self::newLedgerToken()])->saveQuietly();
        }

        return route('customer-ledger.show', $this->ledger_token);
    }

    public function rotateLedgerToken(): void
    {
        $this->forceFill(['ledger_token' => self::newLedgerToken()])->saveQuietly();
    }

    protected static function newLedgerToken(): string
    {
        return \Illuminate\Support\Str::random(32);
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
        return Sale::withoutGlobalScope(BranchScope::class)
            ->where('customer_id', $this->id)
            ->owedByCustomer()
            ->orderBy('sales.id')
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
     * their own customer-linked records. A cancelled sale still counts —
     * it's deliberately not excluded here the way it is from totals/reports,
     * since it's still a real historical record tying this customer to the
     * shop's data.
     */
    public function hasFinancialHistory(): bool
    {
        return $this->udhaarTransactions()->exists()
            || Sale::withoutGlobalScope(ExcludesCancelledSalesScope::class)->where('customer_id', $this->id)->exists()
            || $this->walletLoads()->exists()
            || $this->balanceLoads()->exists()
            || $this->billPayments()->exists()
            || $this->repairs()->exists()
            || $this->nadraVerifications()->exists()
            || $this->simSales()->exists()
            || $this->servicePayments()->exists()
            || StoreCreditEntry::withoutGlobalScopes()->where('customer_id', $this->id)->exists()
            || Cheque::withoutGlobalScopes()->where('customer_id', $this->id)->exists()
            || ContraSettlement::withoutGlobalScopes()->where('customer_id', $this->id)->exists()
            || SalesOrder::withoutGlobalScopes()->where('customer_id', $this->id)->exists()
            || PartyLedgerEntry::withoutGlobalScope(ExcludesCancelledScope::class)->where('customer_id', $this->id)->exists();
    }
}
