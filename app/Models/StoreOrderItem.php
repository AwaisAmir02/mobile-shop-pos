<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOrderItem extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'store_order_id',
        'product_id',
        'external_sku',
        'product_name',
        'quantity',
        'unit_price',
        'removed',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'removed' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
