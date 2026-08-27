<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasImage;
use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    use BelongsToShop, HasImage;

    public const DEFAULTS = ['Jazz', 'Zong', 'Telenor', 'Ufone'];

    protected $fillable = [
        'shop_id',
        'name',
        'image_path',
        'color',
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
