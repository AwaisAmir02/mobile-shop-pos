<?php

namespace App\Models;

use App\Enums\AdCampaignStatus;
use App\Enums\AdGoal;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdCampaign extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'created_by',
        'shop_channel_connection_id',
        'marketing_post_id',
        'goal',
        'name',
        'status',
        'currency',
        'daily_budget',
        'start_at',
        'end_at',
        'targeting',
        'meta_campaign_id',
        'meta_adset_id',
        'meta_image_hash',
        'meta_creative_id',
        'meta_ad_id',
        'effective_status',
        'review_feedback',
        'error',
        'launched_by',
        'launched_at',
        'spend_cap_confirmed',
        'metrics',
        'metrics_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'goal' => AdGoal::class,
            'status' => AdCampaignStatus::class,
            'daily_budget' => 'decimal:2',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'targeting' => 'array',
            'launched_at' => 'datetime',
            'spend_cap_confirmed' => 'decimal:2',
            'metrics' => 'array',
            'metrics_synced_at' => 'datetime',
        ];
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(ShopChannelConnection::class, 'shop_channel_connection_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(MarketingPost::class, 'marketing_post_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function days(): int
    {
        return max(1, (int) ceil($this->start_at->diffInMinutes($this->end_at) / 1440));
    }

    public function maxSpend(): float
    {
        return round((float) $this->daily_budget * $this->days(), 2);
    }

    public function metric(string $key): float
    {
        return (float) (($this->metrics ?? [])[$key] ?? 0);
    }

    public function isFullyCreated(): bool
    {
        return $this->meta_campaign_id && $this->meta_adset_id && $this->meta_creative_id && $this->meta_ad_id;
    }
}
