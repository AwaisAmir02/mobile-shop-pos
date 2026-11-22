<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\QuantityUnit;
use App\Enums\WarrantyType;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'product_id',
        'stock_in_id',
        'product_name',
        'product_type',
        'serial_number',
        'warranty_months',
        'warranty_type',
        'unit_price',
        'list_price',
        'cost_price',
        'quantity',
        'unit',
        'pack_name',
        'pack_size',
        'discount_amount',
        'promotion_id',
        'promotion_name',
        'promotion_discount',
        'line_total',
    ];

    protected $attributes = ['unit' => 'piece'];

    /**
     * The pack a product line was sold in, kept only when the line really is
     * whole packs of it (a piece count that divides exactly).
     *
     * @return array{pack_name: ?string, pack_size: ?int}
     */
    public static function packAttributes(?string $name, mixed $size, mixed $quantity): array
    {
        $size = (int) $size;
        $quantity = Quantity::normalize($quantity);
        $name = $name !== null ? mb_substr(trim($name), 0, 20) : null;

        return $name !== null && $name !== '' && $size > 1 && is_int($quantity) && $quantity > 0 && $quantity % $size === 0
            ? ['pack_name' => $name, 'pack_size' => $size]
            : ['pack_name' => null, 'pack_size' => null];
    }

    /**
     * "3 strips" when this line was sold in whole packs; null otherwise. A
     * line split across batches can hold part of a pack, which reads as
     * "part of a strip sale".
     */
    public function packLabel(): ?string
    {
        if ($this->pack_name === null || (int) $this->pack_size < 2) {
            return null;
        }

        $quantity = Quantity::normalize($this->quantity);

        return is_int($quantity) && $quantity % (int) $this->pack_size === 0
            ? Product::countLabel($this->pack_name, intdiv($quantity, (int) $this->pack_size))
            : 'part of a '.mb_strtolower($this->pack_name).' sale';
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withoutGlobalScopes();
    }

    /** "Eid Sale: Rs 56.00 off" when a promotion took something off this line. */
    public function promotionNote(): ?string
    {
        return $this->promotion_name !== null && (float) $this->promotion_discount > 0
            ? $this->promotion_name.': Rs '.number_format((float) $this->promotion_discount, 2).' off'
            : null;
    }

    /** Its quantity taken back by active returns. */
    public function returnedQuantity(): int|float
    {
        return \App\Support\Quantity::normalize(SaleReturnItem::withoutGlobalScopes()
            ->where('sale_item_id', $this->id)
            ->whereIn('sale_return_id', SaleReturn::withoutGlobalScopes()->where('status', 'active')->select('id'))
            ->sum('quantity'));
    }

    /** Its value taken back by active returns. */
    public function returnedValue(): float
    {
        return round((float) SaleReturnItem::withoutGlobalScopes()
            ->where('sale_item_id', $this->id)
            ->whereIn('sale_return_id', SaleReturn::withoutGlobalScopes()->where('status', 'active')->select('id'))
            ->sum('value'), 2);
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'discount_amount' => 'decimal:2',
            'promotion_discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'warranty_months' => 'integer',
            'warranty_type' => WarrantyType::class,
        ];
    }

    /**
     * The warranty and IMEI/serial to freeze on a line selling $product. An edit
     * keeps what the line already had, so changing the product's default later
     * never changes a warranty already given.
     *
     * @return array{serial_number: ?string, warranty_months: ?int, warranty_type: ?WarrantyType}
     */
    public static function warrantyAttributesFor(Product $product, ?self $previous = null): array
    {
        if ($previous !== null) {
            return ['serial_number' => $previous->serial_number, 'warranty_months' => $previous->warranty_months, 'warranty_type' => $previous->warranty_type];
        }

        $serial = trim((string) ($product->details['imei'] ?? ''));

        return [
            'serial_number' => $serial !== '' ? mb_substr($serial, 0, 50) : null,
            'warranty_months' => $product->warranty_months ?: null,
            'warranty_type' => $product->warranty_months ? ($product->warranty_type ?? WarrantyType::Brand) : null,
        ];
    }

    public function hasWarranty(): bool
    {
        return (int) $this->warranty_months > 0;
    }

    /** The last day of cover: sold on 15 Jan with 12 months runs to 14 Jan next year. */
    public function warrantyEndsOn(\Carbon\CarbonInterface $soldOn): ?\Carbon\CarbonImmutable
    {
        return $this->hasWarranty()
            ? \Carbon\CarbonImmutable::parse($soldOn->toDateString())->addMonthsNoOverflow($this->warranty_months)->subDay()
            : null;
    }

    public function wasPriceOverridden(): bool
    {
        return $this->list_price !== null && (float) $this->list_price !== (float) $this->unit_price;
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class);
    }
}
