<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\PaymentStatus;
use App\Enums\QuantityUnit;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use App\Support\BatchExpiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockIn extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    protected $fillable = [
        'shop_id',
        'product_id',
        'vendor_id',
        'product_name',
        'user_id',
        'quantity',
        'unit',
        'cost_price',
        'selling_price',
        'quantity_remaining',
        'total_cost',
        'payment_status',
        'amount_paid',
        'stock_date',
        'expiry_date',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['unit' => 'piece'];

    /**
     * Stock is one shared pool across branches in Phase 1, and these rows
     * are also the FIFO batches every branch sells from, so they must never
     * be hidden by branch. Stock-In lists use inCurrentBranch() instead.
     */
    public static function appliesBranchScope(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'quantity_remaining' => QuantityCast::class,
            'total_cost' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'amount_paid' => 'decimal:2',
            'stock_date' => 'date',
            'expiry_date' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Dated history of amount_paid — see StockInPayment. Read-only here:
     * amount_paid itself is still the figure every total uses.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(StockInPayment::class);
    }

    public function amountOwed(): float
    {
        if ($this->payment_status === PaymentStatus::Paid) {
            return 0.0;
        }

        return max(0.0, (float) ($this->total_cost ?? 0) - (float) $this->amount_paid);
    }

    /**
     * The one definition of which batch sells next. Off: oldest received
     * first (FIFO). On (FEFO): unexpired with the soonest date, then undated,
     * then expired last, so naive date order can't sell expired stock first.
     * The CASE also settles NULLs, which MySQL would otherwise sort first.
     * Whatever the date order, ties fall back to FIFO.
     */
    public function scopeInSellOrder(Builder $query, bool $byExpiry): void
    {
        if ($byExpiry) {
            $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 WHEN expiry_date < ? THEN 2 ELSE 0 END', [BatchExpiry::today()])
                ->orderBy('expiry_date');
        }

        $query->orderBy('stock_date')->orderBy('id');
    }

    /** $byExpiry comes from the caller when it has the product; otherwise it's looked up, never assumed. */
    public static function availableBatchesFor(int $productId, ?bool $byExpiry = null)
    {
        $byExpiry ??= (bool) Product::withoutGlobalScopes()->whereKey($productId)->first()?->sellsByExpiry();

        return static::query()
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0)
            ->inSellOrder($byExpiry);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->toDateString() < BatchExpiry::today();
    }
}
