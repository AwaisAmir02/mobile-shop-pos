<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubCategory extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'category_id',
        'name',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
