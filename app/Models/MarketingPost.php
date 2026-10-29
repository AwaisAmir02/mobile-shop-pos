<?php

namespace App\Models;

use App\Enums\PostKind;
use App\Enums\PostStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketingPost extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'created_by',
        'kind',
        'title',
        'caption',
        'ref_code',
        'status',
        'image_path',
        'story_image_path',
        'customer_review_id',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PostKind::class,
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public static function newRefCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (! preg_match('/^[A-Z0-9]{6}$/', $code) || static::withoutGlobalScopes()->where('ref_code', $code)->exists());

        return $code;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'marketing_post_products')->withPivot('position')->withTimestamps()->orderBy('marketing_post_products.position');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(MarketingPostTarget::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(CustomerReview::class, 'customer_review_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function storyImageUrl(): ?string
    {
        return $this->story_image_path ? Storage::disk('public')->url($this->story_image_path) : null;
    }

    public function refTag(): string
    {
        return 'REF-'.$this->ref_code;
    }

    public function refreshStatusFromTargets(): void
    {
        $statuses = $this->targets()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $this->status = match (true) {
            $statuses->every(fn ($s) => $s === 'published') => PostStatus::Published,
            $statuses->contains('pending') || $statuses->contains('publishing') => PostStatus::Publishing,
            $statuses->contains('published') => PostStatus::Partial,
            default => PostStatus::Failed,
        };

        if ($this->published_at === null && $statuses->contains('published')) {
            $this->published_at = now();
        }

        $this->save();
    }
}
