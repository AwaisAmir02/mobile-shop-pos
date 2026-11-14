<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\QuantityUnit;
use App\Enums\WarrantyType;
use App\Models\Concerns\BelongsToShop;
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
        'discount_amount',
        'line_total',
    ];

    protected $attributes = ['unit' => 'piece'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'discount_amount' => 'decimal:2',
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
