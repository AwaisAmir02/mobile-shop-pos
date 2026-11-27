<?php

namespace App\Models;

use App\Enums\WebsiteRequestStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop asking us to build its website (O9, App\Actions\WebsiteRequests). The
 * conversation, photos and files are its support ticket's thread; this holds the
 * requirements and, once delivered, which store connection the site was given.
 */
class WebsiteRequest extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'support_ticket_id', 'user_id', 'status', 'requirements', 'store_connection_id', 'site_url', 'admin_note', 'fulfilled_at', 'fulfilled_by'];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return ['status' => WebsiteRequestStatus::class, 'requirements' => 'array', 'fulfilled_at' => 'datetime'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_connection_id');
    }

    public function number(): string
    {
        return 'WR-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    /** Not yet started by us: the admin sidebar's count. */
    public function scopeWaitingOnAdmin(Builder $query): Builder
    {
        return $query->withoutGlobalScopes()->where('status', WebsiteRequestStatus::Submitted->value);
    }
}
