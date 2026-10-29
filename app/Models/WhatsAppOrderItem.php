<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
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
        'quoted_unit_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
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
        return (float) $this->quoted_unit_price * $this->quantity;
    }
}
