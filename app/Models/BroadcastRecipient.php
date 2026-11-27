<?php

namespace App\Models;

use App\Enums\BroadcastDelivery;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class BroadcastRecipient extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'broadcast_id', 'customer_id', 'name', 'phone', 'delivery', 'error'];

    protected function casts(): array
    {
        return ['delivery' => BroadcastDelivery::class];
    }
}
