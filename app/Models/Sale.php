<?php

namespace App\Models;

use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\BranchScope;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = [
        'shop_id',
        'user_id',
        'customer_id',
        'source',
        'subtotal',
        'discount_amount',
        'total',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ExcludesCancelledSalesScope);
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => SaleStatus::class,
            'source' => SaleSource::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** The instalment plan, if this sale was sold on one. See App\Support\InstallmentSchedule. */
    public function installments(): HasMany
    {
        return $this->hasMany(SaleInstallment::class)->orderBy('sequence');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SaleRevision::class)->latest();
    }

    /** Its active returns, whichever branch took them back (cancelled ones are left out by their own scope). */
    /** Its lines in the prescription / controlled-drug register. */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(SalePrescription::class)->withoutGlobalScopes()->orderBy('serial_number');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->withoutGlobalScope(BranchScope::class);
    }

    public function hasReturns(): bool
    {
        if (array_key_exists('returns_count', $this->attributes)) {
            return (int) $this->attributes['returns_count'] > 0;
        }

        return $this->exists && $this->returns()->exists();
    }

    /** What returns have taken off the amount owed (the rest of their value was refunded). */
    public function returnedAgainstDue(): float
    {
        if (array_key_exists('returns_sum_due_reduction', $this->attributes)) {
            return (float) ($this->attributes['returns_sum_due_reduction'] ?? 0);
        }

        return $this->exists ? (float) $this->returns()->sum('due_reduction') : 0.0;
    }

    /** The value of everything returned from this sale. */
    public function returnedValue(): float
    {
        if (array_key_exists('returns_sum_value_total', $this->attributes)) {
            return (float) ($this->attributes['returns_sum_value_total'] ?? 0);
        }

        return $this->exists ? (float) $this->returns()->sum('value_total') : 0.0;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === SaleStatus::Cancelled;
    }

    /**
     * Route-model binding must still find a cancelled sale (the show page
     * and invoice download both need to render it, with a CANCELLED stamp)
     * — only the tenant ShopScope stays enforced here. Each route decides
     * for itself whether a cancelled sale is allowed past that
     * point (e.g. the edit screen explicitly rejects it after loading).
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->withoutGlobalScope(ExcludesCancelledSalesScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function invoiceNumber(): string
    {
        return 'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function amountPaid(): float
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (float) ($this->attributes['payments_sum_amount'] ?? 0);
        }

        return (float) $this->payments()->sum('amount');
    }

    /**
     * The total, less payments, less what returns took off it. The total and
     * payments stay as they were; a return is its own dated document.
     */
    public function amountDue(): float
    {
        $returned = $this->returnedAgainstDue();

        // Exactly the old sum when nothing was returned.
        return $returned == 0.0
            ? max(0.0, (float) $this->total - $this->amountPaid())
            : max(0.0, round((float) $this->total - $this->amountPaid() - $returned, 2));
    }

    public function paymentStatus(): string
    {
        if ($this->amountDue() <= 0.0) {
            return 'paid';
        }

        return $this->amountPaid() > 0.0 ? 'partial' : 'unpaid';
    }
}
