<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillProvider extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'bill_category_id',
        'name',
        'region',
    ];

    public function billCategory(): BelongsTo
    {
        return $this->belongsTo(BillCategory::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }
}
