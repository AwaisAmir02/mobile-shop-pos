<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * Defined by each shop; the app holds no tax or statutory rule of any kind.
 * reduces_cost: a fine or unpaid leave lowers what the salary costs the shop.
 * Otherwise it's held for someone else: still a cost, just not take-home pay.
 */
class DeductionType extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'name', 'reduces_cost', 'default_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'reduces_cost' => 'boolean',
            'default_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
