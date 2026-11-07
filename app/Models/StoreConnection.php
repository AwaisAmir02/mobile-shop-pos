<?php

namespace App\Models;

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

    protected $hidden = ['signing_secret', 'previous_signing_secret'];

    protected static function booted(): void
    {
        static::saved(fn () => StoreChanges::current()->forget());
    }

    protected function casts(): array
    {
        return [
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

    /**
     * Read from the shop directly: whoever's asking may be an API request. Off
     * includes a lapsed plan or add-on, which turn the shop's switch off.
     */
    public function isLive(): bool
    {
        return $this->status === self::ACTIVE
            && $this->removed_at === null
            && (bool) Shop::whereKey($this->shop_id)->value('online_store_enabled');
    }
}
