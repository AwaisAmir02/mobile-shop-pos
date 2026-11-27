<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'sales_order_id', 'product_id', 'product_name', 'quantity', 'pack_name', 'pack_size', 'unit_price', 'price_tier', 'discount'];

    protected function casts(): array
    {
        return ['quantity' => QuantityCast::class, 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'pack_size' => 'integer', 'price_tier' => \App\Enums\PriceTier::class];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): float
    {
        return max(0.0, round(Quantity::money($this->unit_price, $this->quantity) - (float) $this->discount, 2));
    }

    /** "3 cartons (36)" when ordered in whole packs, else the plain quantity. */
    public function quantityLabel(): string
    {
        $pieces = (float) $this->quantity;

        if ($this->pack_name && $this->pack_size > 1 && fmod($pieces, $this->pack_size) == 0.0) {
            return Product::countLabel($this->pack_name, (int) ($pieces / $this->pack_size)).' ('.Quantity::grouped($this->quantity).')';
        }

        return Quantity::grouped($this->quantity);
    }
}
