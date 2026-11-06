<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\QuantityUnit;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One batch received. Creates exactly one stock_ins row, which holds the real stock and dues. */
class GrnLine extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'grn_id',
        'po_line_id',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'cost_price',
        'selling_price',
        'expiry_date',
    ];

    protected $attributes = ['unit' => 'piece'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class);
    }

    public function stockIn(): HasOne
    {
        return $this->hasOne(StockIn::class);
    }
}
