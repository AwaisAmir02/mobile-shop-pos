<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class AccessoryCategoryOption extends Model
{
    use BelongsToShop;

    public const DEFAULTS = [
        'Case / Cover',
        'Charger',
        'Cable',
        'Screen Protector',
        'Earphones',
        'Power Bank',
        'Other',
    ];

    protected $fillable = [
        'shop_id',
        'name',
    ];

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
