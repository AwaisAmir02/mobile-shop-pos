<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ShopSim extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'number',
        'network',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
