<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\QuantityUnit;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppOrderItem extends Model
{
    use BelongsToShop;

    protected $table = 'whatsapp_order_items';

    protected $fillable = [
        'shop_id',
        'whatsapp_order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'quoted_unit_price',
    ];

    protected $attributes = ['unit' => 'piece'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit' => QuantityUnit::class,
            'quoted_unit_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOrder::class, 'whatsapp_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): float
    {
        return Quantity::money($this->quoted_unit_price, $this->quantity);
    }
}
