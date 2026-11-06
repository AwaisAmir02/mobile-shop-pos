<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\QuantityUnit;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoLine extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'purchase_order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'expected_cost',
    ];

    protected $attributes = ['unit' => 'piece'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'expected_cost' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lines of GRNs that haven't been cancelled. */
    public function activeGrnLines(): HasMany
    {
        return $this->hasMany(GrnLine::class)->whereHas('grn');
    }

    /** Always summed from the GRN lines; there's no stored running total to drift. */
    public function receivedQuantity(): int|float
    {
        return Quantity::sum($this->activeGrnLines->pluck('quantity'));
    }

    public function outstandingQuantity(): int|float
    {
        return max(0, Quantity::subtract($this->quantity, $this->receivedQuantity()));
    }
}
