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
        'price',
        'duration_minutes',
        'capacity',
        'is_active',
    ];

    protected $attributes = ['capacity' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'duration_minutes' => 'integer', 'capacity' => 'integer', 'is_active' => 'boolean'];
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function servicePayments(): HasMany
    {
        return $this->hasMany(ServicePayment::class);
    }
}
