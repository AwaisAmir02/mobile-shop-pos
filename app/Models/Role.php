<?php

namespace App\Models;

use App\Enums\ShopScreen;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasAccess(ShopScreen|string $screen): bool
    {
        $value = $screen instanceof ShopScreen ? $screen->value : $screen;

        return in_array($value, $this->permissions ?? [], true);
    }
}
