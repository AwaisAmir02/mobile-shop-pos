<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessoryCategoryOption extends Model
{
    use BelongsToShop, HasImage;

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
        'main_category_id',
        'name',
        'image_path',
    ];

    public function mainCategory(): BelongsTo
    {
        return $this->belongsTo(MainCategory::class);
    }

    public static function ensureDefaultsExist(): void
    {
        if (static::query()->exists()) {
            return;
        }

        MainCategory::ensureDefaultsExist();
        $accessory = MainCategory::where('slug', 'accessory')->firstOrFail();

        foreach (self::DEFAULTS as $name) {
            static::create(['main_category_id' => $accessory->id, 'name' => $name]);
        }
    }
}
