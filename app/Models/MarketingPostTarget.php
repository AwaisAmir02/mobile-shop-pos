<?php

namespace App\Models;

use App\Enums\PostSurface;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingPostTarget extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'marketing_post_id',
        'shop_channel_connection_id',
        'surface',
        'status',
        'external_id',
        'permalink',
        'error',
        'attempts',
        'published_at',
        'metrics',
        'metrics_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'surface' => PostSurface::class,
            'attempts' => 'integer',
            'published_at' => 'datetime',
            'metrics' => 'array',
            'metrics_synced_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(MarketingPost::class, 'marketing_post_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ShopChannelConnection::class, 'shop_channel_connection_id');
    }

    public function metric(string $key): ?int
    {
        $value = ($this->metrics ?? [])[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
