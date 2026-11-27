<?php

namespace App\Models;

use App\Enums\StoreDriver;
use App\Models\Concerns\BelongsToShop;
use App\Support\StoreChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One online store connected to one shop. Its credentials only ever reach that shop. */
class StoreConnection extends Model
{
    use BelongsToShop;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    protected $fillable = [
        'shop_id',
        'public_id',
        'driver',
        'external_domain',
        'credentials',
        'settings',
        'connected_at',
        'name',
        'status',
        'signing_secret',
        'previous_signing_secret',
        'previous_secret_expires_at',
        'callback_url',
        'delivery_paused_at',
        'last_delivered_at',
        'last_delivery_error',
        'price_sync',
        'safety_buffer',
        'shop_account_id',
        'created_by',
        'removed_at',
    ];

    protected $hidden = ['signing_secret', 'previous_signing_secret', 'credentials'];

    protected $attributes = ['driver' => 'api'];

    protected static function booted(): void
    {
        static::saved(fn () => StoreChanges::current()->forget());
    }

    protected function casts(): array
    {
        return [
            'driver' => StoreDriver::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'connected_at' => 'datetime',
            'delivery_paused_at' => 'datetime',
            'last_delivered_at' => 'datetime',
            'signing_secret' => 'encrypted',
            'previous_signing_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'price_sync' => 'boolean',
            'safety_buffer' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(StoreApiToken::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function scopeNotRemoved(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /** Connections a shop's own developer builds on (docs/channel-api-v1.md), not Shopify, WooCommerce or Daraz. */
    public function scopeOwnApi(Builder $query): void
    {
        $query->where('driver', StoreDriver::Api->value);
    }

    /**
     * Connections that are sent changes: an API connection with a callback URL, or a
     * Shopify or WooCommerce store once connected (its outbox goes to the platform's API).
     */
    public function scopeSendingChanges(Builder $query): void
    {
        $platforms = array_map(fn (StoreDriver $driver) => $driver->value, array_filter(StoreDriver::cases(), fn (StoreDriver $driver) => $driver->isPlatform()));

        $query->where(fn ($q) => $q->whereNotNull('callback_url')
            ->orWhere(fn ($platform) => $platform->whereIn('driver', $platforms)->whereNotNull('connected_at')));
    }

    public function sendsChanges(): bool
    {
        return filled($this->callback_url) || ($this->driver->isPlatform() && $this->connected_at !== null);
    }

    /**
     * Read from the shop directly: whoever's asking may be an API request. Off
     * includes a lapsed plan or add-on, which turn the shop's switch off.
     */
    public function isLive(): bool
    {
        return $this->status === self::ACTIVE
            && $this->removed_at === null
            && $this->featureOn();
    }

    /**
     * The shop may use this connection: the plan's Online store for the API and
     * the platform stores; a Daraz import is the Marketplace tool alone (O6),
     * since nothing is published to Daraz from here.
     */
    public function featureOn(): bool
    {
        $shop = Shop::find($this->shop_id);

        return $this->driver->isMarketplace()
            ? (bool) $shop?->usesTool(\App\Enums\OnlineTool::Marketplace)
            : (bool) $shop?->online_store_enabled;
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
