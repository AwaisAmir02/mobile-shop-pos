<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one write-off took from one batch, at that batch's cost. Shaped for RestoreStockFifo. */
class StockWriteOffItem extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'stock_write_off_id',
        'product_id',
        'stock_in_id',
        'product_name',
        'quantity',
        'cost_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'cost_price' => 'decimal:2',
        ];
    }

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(StockWriteOff::class, 'stock_write_off_id');
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class);
    }
}
