<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Only a hash is kept, so a token is shown once and can't be recovered from a leaked database or backup. */
class StoreApiToken extends Model
{
    public const SCOPES = ['catalog:read', 'inventory:read', 'orders:read'];

    protected $fillable = [
        'store_connection_id',
        'token_hash',
        'token_prefix',
        'scopes',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_connection_id');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
