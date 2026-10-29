<?php

namespace App\Models;

use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToShop;

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

    public function revisions(): HasMany
    {
        return $this->hasMany(SaleRevision::class)->latest();
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
     * for itself whether a cancelled sale is actually allowed past that
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

    public function amountDue(): float
    {
        return max(0.0, (float) $this->total - $this->amountPaid());
    }

    public function paymentStatus(): string
    {
        if ($this->amountDue() <= 0.0) {
            return 'paid';
        }

        return $this->amountPaid() > 0.0 ? 'partial' : 'unpaid';
    }
}
