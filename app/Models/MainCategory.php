<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ShopScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MainCategory extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'slug',
        'is_builtin',
    ];

    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
        ];
    }

    public function subCategories(): HasMany
    {
        return $this->hasMany(AccessoryCategoryOption::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'type', 'slug');
    }

    public static function ensureDefaultsExist(?int $shopId = null): void
    {
        $shopId ??= Auth::user()?->shop_id;

        if (! $shopId) {
            return;
        }

        if (static::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->exists()) {
            return;
        }

        static::create(['shop_id' => $shopId, 'name' => 'Mobile Phone', 'slug' => 'mobile', 'is_builtin' => true]);
        static::create(['shop_id' => $shopId, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    public static function uniqueSlugFor(string $name): string
    {
        $base = static::slugFor($name);
        $slug = $base;
        $attempt = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$attempt);
        }

        return $slug;
    }
}
