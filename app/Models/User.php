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
        ];
    }

    public function hasAccessTo(ShopScreen|string $screen): bool
    {
        if ($this->is_owner) {
            return true;
        }

        return $this->role?->hasAccess($screen) ?? false;
    }

    public function firstAccessibleScreenRouteName(): ?string
    {
        foreach (ShopScreen::cases() as $screen) {
            if ($this->hasAccessTo($screen)) {
                return $screen->routeName();
            }
        }

        return null;
    }
}
