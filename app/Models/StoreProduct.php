<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product on one connection, with the store's own identifier for it. Unpublishing
 * keeps the row, so a store asking what changed is told the product has gone.
 */
class StoreProduct extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'store_connection_id',
        'product_id',
        'external_sku',
        'published',
    ];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isOnSale(): bool
    {
        return $this->published && $this->product?->blocksOnlinePublishing() === null;
    }
}
