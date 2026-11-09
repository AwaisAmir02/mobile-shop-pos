<?php

namespace App\Models;

use App\Enums\ShopScreen;
use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'shop_id',
        'role_id',
        'branch_id',
        'is_super_admin',
        'is_owner',
        'is_active',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * A removed account takes its profile photo with it.
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
        });
    }

    /** The profile photo's URL, or null for the initial-letter avatar. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /** The letter shown when there's no photo. */
    public function initial(): string
    {
        return Str::upper(Str::substr((string) $this->name, 0, 1)) ?: '?';
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** The one branch this staff member is limited to; null means every branch. Ignored for the owner. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isBranchRestricted(): bool
    {
        return ! $this->is_owner && $this->branch_id !== null;
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(UserSocialAccount::class);
    }

    public function loginLabel(): string
    {
        return $this->email ?? ($this->phone ? PhoneNumber::display($this->phone) : '—');
    }

    public function hasPassword(): bool
    {
        return filled($this->getAuthPassword());
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_owner' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function hasAccessTo(ShopScreen|string $screen): bool
    {
        if ($this->shop && ($this->shop->isScreenDisabled($screen) || $this->shop->isScreenHidden($screen))) {
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

    /**
     * Barcode support is a shop-wide capability switch (shops.barcode_enabled,
     * on by default, flipped only by Super Admin), deliberately not a
     * ShopScreen: a screen entry would appear as a per-Role checkbox, and
     * existing cashiers hold no such permission yet, so scanning would
     * silently stop working for them the day this ships.
     */
    public function canUseBarcodes(): bool
    {
        return (bool) ($this->shop?->barcode_enabled);
    }
}
