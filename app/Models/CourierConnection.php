<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\CourierKey;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop's own account with one courier. The credentials are the shop's,
 * issued to it by the courier, and stored encrypted. We only ever call the
 * courier on the shop's behalf; no courier money passes through us.
 */
class CourierConnection extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'courier',
        'credentials',
        'settings',
        'status',
        'last_error',
        'verified_at',
        'connected_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'courier' => CourierKey::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'status' => ConnectionStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Connected;
    }

    public function credential(string $key, ?string $default = null): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return $value === null || $value === '' ? $default : (string) $value;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
