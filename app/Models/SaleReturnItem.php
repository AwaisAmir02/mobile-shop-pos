<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\QuantityUnit;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What came back from one sale_items row: the quantity, what it was worth and
 * its cost, both as sold. 'restock' puts it back on the batch it was sold
 * from; 'damaged' does too and then writes it off from that batch, so the
 * loss shows on the write-off screen. A damaged unit of old stock with no
 * batch isn't put back at all (restocked = false).
 */
class SaleReturnItem extends Model
{
    use BelongsToShop;

    public const RESTOCK = 'restock';

    public const DAMAGED = 'damaged';

    protected $fillable = [
        'shop_id',
        'sale_return_id',
        'sale_item_id',
        'product_id',
        'stock_in_id',
        'product_name',
        'serial_number',
        'quantity',
        'unit',
        'cost_price',
        'value',
        'disposition',
        'restocked',
        'stock_write_off_id',
    ];

    protected $attributes = ['unit' => 'piece', 'disposition' => self::RESTOCK, 'restocked' => true];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'cost_price' => 'decimal:2',
            'value' => 'decimal:2',
            'restocked' => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
