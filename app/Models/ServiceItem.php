<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceItem extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'service_category_id',
        'name',
    ];

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function servicePayments(): HasMany
    {
        return $this->hasMany(ServicePayment::class);
    }
}
