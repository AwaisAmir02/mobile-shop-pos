<?php

namespace App\Models;

use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopChannelConnection extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'provider',
        'external_id',
        'waba_id',
        'display_name',
        'access_token',
        'token_expires_at',
        'scopes',
        'meta',
        'status',
        'last_error',
        'connected_at',
        'connected_by',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'provider' => ChannelProvider::class,
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
            'connected_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConnectionStatus::Connected->value);
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Connected;
    }

    public static function resolveWhatsApp(string $phoneNumberId): ?self
    {
        return static::withoutGlobalScopes()
            ->where('provider', ChannelProvider::WhatsApp->value)
            ->where('external_id', $phoneNumberId)
            ->where('status', ConnectionStatus::Connected->value)
            ->first();
    }

    public static function activeFor(int $shopId, ChannelProvider $provider): ?self
    {
        return static::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('provider', $provider->value)
            ->where('status', ConnectionStatus::Connected->value)
            ->latest('connected_at')
            ->first();
    }
}
