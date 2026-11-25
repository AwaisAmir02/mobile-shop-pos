<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'purchase_return_id', 'stock_in_id', 'product_id', 'product_name', 'quantity', 'cost_price', 'line_value'];

    protected function casts(): array
    {
        return ['quantity' => QuantityCast::class, 'cost_price' => 'decimal:2', 'line_value' => 'decimal:2'];
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class)->withoutGlobalScopes();
    }
}
