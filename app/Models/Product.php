<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuantityUnit;
use App\Enums\SimForm;
use App\Enums\SimType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasImage;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use BelongsToShop, HasImage {
        imageUrl as ownImageUrl;
    }

    public const LOW_STOCK_THRESHOLD = 5;

    protected $fillable = [
        'shop_id',
        'product_group_id',
        'variant_label',
        'type',
        'name',
        'barcode',
        'image_path',
        'price',
        'cost_price',
        'stock_quantity',
        'unit',
        'shelf_life_days',
        'whatsapp_listed',
        'archived_at',
        'details',
    ];

    protected $attributes = ['unit' => 'piece'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'shelf_life_days' => 'integer',
            'whatsapp_listed' => 'boolean',
            'archived_at' => 'datetime',
            'details' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Every save, whichever screen or service it came from.
        static::saving(function (Product $product) {
            $product->enforceUnitRules();
        });

        static::updated(function (Product $product) {
            if ($product->archived_at !== null && $product->stock_quantity > 0) {
                $product->archived_at = null;
                $product->saveQuietly();
            }
        });
    }

    protected function enforceUnitRules(): void
    {
        $unit = $this->unit ?? QuantityUnit::Piece;

        if ($unit->allowsDecimals()) {
            if (in_array($this->type, QuantityUnit::PIECE_ONLY_TYPES, true) || ! empty($this->details['imei'])) {
                throw ValidationException::withMessages(['unit' => 'Mobiles, IMEI-tracked items and SIMs are sold by the piece.']);
            }

            // Ordering by weight on WhatsApp isn't offered, so a measured product can't be listed.
            $this->whatsapp_listed = false;
        }

        if ($this->exists && $this->isDirty('unit') && $this->unitIsLocked()) {
            throw ValidationException::withMessages(['unit' => "{$this->name} already has stock-in, sales or purchase order records, so its unit can't be changed."]);
        }

        if (($this->isDirty('stock_quantity') || $this->isDirty('unit')) && ! $unit->allowsDecimals() && ! Quantity::isWhole($this->stock_quantity)) {
            throw ValidationException::withMessages(['stock_quantity' => 'This product is sold by the piece, so its stock must be a whole number.']);
        }

        if ($this->product_group_id !== null && ($this->isDirty('unit') || $this->isDirty('product_group_id'))) {
            $groupUnit = ProductGroup::withoutGlobalScopes()->whereKey($this->product_group_id)->first()?->unit;

            if ($groupUnit !== null && $groupUnit !== $unit) {
                throw ValidationException::withMessages(['unit' => 'Every variant in a group has to use the same unit.']);
            }
        }
    }

    /**
     * Locked like its price once it has batches. Sales lock it too: cancelling
     * one puts its quantity back, which has to still make sense in this unit.
     * So does an open purchase order line.
     */
    public function unitIsLocked(): bool
    {
        return $this->hasBatches()
            || SaleItem::withoutGlobalScopes()->where('product_id', $this->id)->exists()
            || $this->hasOpenPoLine();
    }

    /** An order on the way was placed in this unit, and will be received in it. */
    public function hasOpenPoLine(): bool
    {
        return PoLine::withoutGlobalScopes()
            ->where('product_id', $this->id)
            ->whereHas('order', fn ($query) => $query->withoutGlobalScopes()->whereIn('status', collect(PurchaseOrderStatus::cases())->filter->locksProductUnit()->pluck('value')))
            ->exists();
    }

    public function canUseMeasuredUnits(): bool
    {
        return ! in_array($this->type, QuantityUnit::PIECE_ONLY_TYPES, true) && empty($this->details['imei']);
    }

    /** "1.5 kg" of stock; a piece count is just the number. */
    public function stockLabel(): string
    {
        return $this->unit->amount($this->stock_quantity);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function canBeArchived(): bool
    {
        return ! $this->isArchived() && $this->stock_quantity <= 0;
    }

    public function mainCategory(): BelongsTo
    {
        return $this->belongsTo(MainCategory::class, 'type', 'slug');
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function isVariant(): bool
    {
        return $this->product_group_id !== null;
    }

    /** IMEI-tracked mobiles and legacy SIMs are one physical unit per row. */
    public function canHaveVariants(): bool
    {
        return ! in_array($this->type, ProductGroup::EXCLUDED_TYPES, true) && empty($this->details['imei']);
    }

    /** A variant with no photo of its own shows its group's. */
    public function imageUrl(): ?string
    {
        return $this->ownImageUrl() ?? ($this->product_group_id !== null ? $this->group?->imageUrl() : null);
    }

    /**
     * Narrows to one row per variant group among $matching (the same filters,
     * as a fresh query); products with no group are unaffected.
     */
    public function scopeOneRowPerGroup(Builder $query, Builder $matching): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('products.product_group_id')
            ->orWhereIn('products.id', $matching
                ->whereNotNull('products.product_group_id')
                ->selectRaw('MIN(products.id)')
                ->groupBy('products.product_group_id')));
    }

    /**
     * Whether this product's shop sells by expiry date. Read from the shop
     * directly: ShopScope only applies when someone is signed in, and the
     * WhatsApp webhook and order acceptance reach here without a user.
     */
    public function sellsByExpiry(): bool
    {
        return (bool) Shop::whereKey($this->shop_id)->value('expiry_tracking_enabled');
    }

    /** Stock that can actually be sold: everything except what sits in expired batches. */
    public function sellableStock(): int|float
    {
        if (! $this->sellsByExpiry()) {
            return $this->stock_quantity;
        }

        $expired = StockIn::availableBatchesFor($this->id, true)->where('expiry_date', '<', BatchExpiry::today())->get(['id', 'quantity_remaining'])->pluck('quantity_remaining');

        return max(0, Quantity::subtract($this->stock_quantity, Quantity::sum($expired)));
    }

    /** Leaves out products with stock left, all of it in expired batches. Out-of-stock products stay. */
    public function scopeNotEntirelyExpired(Builder $query): void
    {
        $query->whereRaw('NOT (products.stock_quantity > 0 AND products.stock_quantity <= (SELECT COALESCE(SUM(expiring.quantity_remaining), 0) FROM stock_ins AS expiring WHERE expiring.product_id = products.id AND expiring.expiry_date < ?))', [BatchExpiry::today()]);
    }

    /**
     * What an online store can be offered: whole-piece products in normal sale.
     * blocksOnlinePublishing() says the same for one product, with the reason.
     */
    public function scopePublishableOnline(Builder $query): void
    {
        $query->where('products.unit', QuantityUnit::Piece->value)
            ->whereNull('products.archived_at')
            ->where('products.type', '!=', 'sim')
            ->where(fn (Builder $q) => $q->whereNull('products.details->imei')->orWhere('products.details->imei', ''));
    }

    public function blocksOnlinePublishing(): ?string
    {
        return match (true) {
            $this->unit !== QuantityUnit::Piece => 'it\'s sold by weight or length',
            $this->isArchived() => 'it\'s archived',
            $this->type === 'sim' => 'SIMs aren\'t offered online',
            ! empty($this->details['imei']) => 'it\'s IMEI-tracked',
            default => null,
        };
    }

    /**
     * currentSellingPrice() for many products in one query: the next
     * batch to sell's price, else the product's own.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, float> keyed by product id
     */
    public static function sellingPricesFor(Collection $products): array
    {
        $batches = StockIn::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('quantity_remaining', '>', 0)
            ->inSellOrder($products->isNotEmpty() && $products->first()->sellsByExpiry())
            ->get(['id', 'product_id', 'selling_price'])
            ->unique('product_id')
            ->keyBy('product_id');

        return $products->mapWithKeys(function (Product $product) use ($batches) {
            $batchPrice = $batches->get($product->id)?->selling_price;

            return [$product->id => $batchPrice !== null ? (float) $batchPrice : (float) $product->price];
        })->all();
    }

    public function nextBatchToSell(): ?StockIn
    {
        return StockIn::availableBatchesFor($this->id, $this->sellsByExpiry())->first();
    }

    public function hasBatches(): bool
    {
        return $this->stockIns()->exists();
    }

    public function currentSellingPrice(): float
    {
        $batch = $this->nextBatchToSell();

        return $batch?->selling_price !== null ? (float) $batch->selling_price : (float) $this->price;
    }

    public function currentCostPrice(): ?float
    {
        $batch = $this->nextBatchToSell();

        if ($batch !== null) {
            return $batch->cost_price !== null ? (float) $batch->cost_price : null;
        }

        return $this->cost_price !== null ? (float) $this->cost_price : null;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= self::LOW_STOCK_THRESHOLD;
    }

    /**
     * The Main Category's own display name, except for legacy SIM products —
     * SIM stopped being a Main Category (or a creatable product type) before
     * Main Categories existed, so no row can ever represent it.
     */
    public function typeLabel(): string
    {
        if ($this->type === 'sim') {
            return 'SIM / eSIM';
        }

        return $this->mainCategory?->name ?? ucfirst($this->type);
    }

    public function summaryLine(): string
    {
        return match ($this->type) {
            'mobile' => trim(
                ($this->details['brand'] ?? '').' '.($this->details['model'] ?? '')
            ).(($this->details['imei'] ?? null) ? ' · IMEI '.$this->details['imei'] : ''),

            'sim' => collect([
                isset($this->details['sim_type']) ? SimType::from($this->details['sim_type'])->label() : null,
                isset($this->details['sim_form']) ? SimForm::from($this->details['sim_form'])->label() : null,
                $this->details['network'] ?? null,
            ])->filter()->implode(' · '),

            default => $this->details['category'] ?? '',
        };
    }
}
