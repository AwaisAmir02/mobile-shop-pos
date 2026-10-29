<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppContact extends Model
{
    use BelongsToShop;

    protected $table = 'whatsapp_contacts';

    protected $fillable = [
        'shop_id',
        'shop_channel_connection_id',
        'wa_id',
        'customer_id',
        'profile_name',
        'language',
        'last_inbound_at',
        'marketing_opt_in_at',
        'marketing_opt_out_at',
        'bot_state',
    ];

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'marketing_opt_in_at' => 'datetime',
            'marketing_opt_out_at' => 'datetime',
            'bot_state' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ShopChannelConnection::class, 'shop_channel_connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WhatsAppOrder::class, 'whatsapp_contact_id');
    }

    public function isInServiceWindow(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }

    public function isMarketingOptedIn(): bool
    {
        return $this->marketing_opt_in_at !== null
            && ($this->marketing_opt_out_at === null || $this->marketing_opt_out_at->lt($this->marketing_opt_in_at));
    }

    public function displayName(): string
    {
        return $this->customer?->name ?? $this->profile_name ?? '+'.$this->wa_id;
    }
}
