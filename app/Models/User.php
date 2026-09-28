<?php

namespace App\Models;

use App\Enums\ShopScreen;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'shop_id',
        'role_id',
        'is_super_admin',
        'is_owner',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_owner' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function hasAccessTo(ShopScreen|string $screen): bool
    {
        if ($this->shop && $this->shop->isScreenDisabled($screen)) {
            return false;
        }

        if ($this->is_owner) {
            return true;
        }

        return $this->role?->hasAccess($screen) ?? false;
    }

    /**
     * Products Excel import is gated by two independent things: the shop
     * must have it turned on by Super Admin (shops.import_enabled — opt-in,
     * off by default, and nothing in this app lets a shop turn it on for
     * itself), AND the user must already have ordinary Products access.
     * There is no separate Role permission for this — see
     * ProductImportAccessTest for why holding 'products' is enough.
     */
    public function canImportProducts(): bool
    {
        return (bool) ($this->shop?->import_enabled) && $this->hasAccessTo(ShopScreen::Products);
    }
}
