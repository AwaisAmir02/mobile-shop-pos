<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something to tell a store, and how telling it went. */
class StoreEvent extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'store_connection_id',
        'public_id',
        'type',
        'data',
        'attempts',
        'next_attempt_at',
        'give_up_at',
        'delivered_at',
        'failed_at',
        'response_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'next_attempt_at' => 'datetime',
            'give_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_connection_id');
    }

    public function scopeUndelivered(Builder $query): void
    {
        $query->whereNull('delivered_at')->whereNull('failed_at');
    }

    public function scopeDue(Builder $query): void
    {
        $query->undelivered()->where('next_attempt_at', '<=', now());
    }

    /** The exact bytes sent, the same on every attempt. */
    public function body(): string
    {
        return (string) json_encode([
            'id' => $this->public_id,
            'type' => $this->type,
            'created_at' => $this->created_at->copy()->timezone(\App\Services\Billing\Entitlements::TIMEZONE)->toIso8601String(),
            'data' => (object) $this->data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->delivered_at !== null => 'Delivered',
            $this->failed_at !== null => 'Gave up',
            $this->attempts > 0 => 'Retrying',
            default => 'Waiting',
        };
    }
}
