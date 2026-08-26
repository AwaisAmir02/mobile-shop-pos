<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIn extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'product_id',
        'product_name',
        'user_id',
        'quantity',
        'stock_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_date' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
