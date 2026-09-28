<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
    ];
}
