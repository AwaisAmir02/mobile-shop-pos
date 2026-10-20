<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    use BelongsToShop;

    public const DEFAULTS = ['Services', 'Installations', 'Downloads'];

    protected $fillable = [
        'shop_id',
        'name',
    ];

    public function serviceItems(): HasMany
    {
        return $this->hasMany(ServiceItem::class);
    }

    public function servicePayments(): HasMany
    {
        return $this->hasMany(ServicePayment::class);
    }

    public static function ensureDefaultsExist(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $name) {
            static::create(['name' => $name]);
        }
    }
}
