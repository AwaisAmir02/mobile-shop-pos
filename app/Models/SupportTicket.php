<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A support conversation between a shop and the platform admin (D13).
 *
 * Who can see a ticket:
 *  - the shop scope (BelongsToShop) keeps every shop to its own tickets;
 *  - inside a shop, the owner sees all of them, staff only the ones they opened;
 *  - the super admin has no shop, so the scope doesn't apply and they see every ticket.
 */
class SupportTicket extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'branch_id',
        'status',
        'screen_route',
        'screen_url',
        'screen_title',
        'device',
        'last_message_at',
        'last_shop_message_at',
        'last_admin_message_at',
        'shop_read_at',
        'admin_read_at',
        'opener_notified_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'last_message_at' => 'datetime',
            'last_shop_message_at' => 'datetime',
            'last_admin_message_at' => 'datetime',
            'shop_read_at' => 'datetime',
            'admin_read_at' => 'datetime',
            'opener_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }

    public function firstMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class)->oldestOfMany();
    }

    /**
     * Owner: every ticket of their shop (the shop scope does the shop part).
     * Staff: only their own. Super admin: all.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->is_super_admin) {
            return $query;
        }

        $query->where('shop_id', $user->shop_id);

        return $user->is_owner ? $query : $query->where('user_id', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return (int) $this->shop_id === (int) $user->shop_id
            && ($user->is_owner || (int) $this->user_id === (int) $user->id);
    }

    /** Support has written since the shop side last looked. */
    public function scopeUnreadForShop(Builder $query): Builder
    {
        return $query->whereNotNull('last_admin_message_at')
            ->where(fn ($q) => $q->whereNull('shop_read_at')->orWhereColumn('last_admin_message_at', '>', 'shop_read_at'));
    }

    /** Not resolved, and the shop has written something the admin hasn't seen. */
    public function scopeWaitingOnAdmin(Builder $query): Builder
    {
        return $query->where('status', '!=', SupportTicketStatus::Resolved->value)
            ->where(fn ($q) => $q->whereNull('admin_read_at')->orWhereColumn('last_shop_message_at', '>', 'admin_read_at'));
    }

    public function isUnreadForShop(): bool
    {
        return $this->last_admin_message_at !== null
            && ($this->shop_read_at === null || $this->last_admin_message_at->gt($this->shop_read_at));
    }

    public function needsAdminAttention(): bool
    {
        return $this->status !== SupportTicketStatus::Resolved
            && ($this->admin_read_at === null || ($this->last_shop_message_at && $this->last_shop_message_at->gt($this->admin_read_at)));
    }

    public function number(): string
    {
        return '#'.$this->id;
    }

    /** What the list shows: the screen's own title, or a readable form of its route. */
    public function screenLabel(): ?string
    {
        if ($this->screen_title) {
            return $this->screen_title;
        }

        if ($this->screen_route) {
            return Str::of($this->screen_route)->replace(['.', '-'], ' ')->title()->toString();
        }

        return null;
    }

    public function preview(int $limit = 90): string
    {
        return Str::limit(Str::squish((string) $this->firstMessage?->body), $limit);
    }

    /**
     * "Chrome on Android" from a user agent. Kept short on purpose: it's there to
     * tell a phone problem from a PC one, not to fingerprint anyone.
     */
    public static function describeDevice(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'CrOS') => 'Chromebook',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        return trim(($browser ?? 'Browser').($os ? ' on '.$os : '')) ?: null;
    }
}
