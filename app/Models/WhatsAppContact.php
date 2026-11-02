<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ShopScope;
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
        'delivery_name',
        'delivery_address',
        'delivery_area',
        'delivery_phone',
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

    public function hasSavedDelivery(): bool
    {
        return filled($this->delivery_name) || filled($this->delivery_phone) || filled($this->delivery_address);
    }

    /**
     * Copies saved details onto the linked Customer, filling only fields the
     * Customer doesn't have yet — staff-entered customer data is never
     * overwritten. The name is never copied onto an existing Customer.
     */
    public function syncDeliveryToCustomer(): void
    {
        if ($this->customer_id === null || ! $this->hasSavedDelivery()) {
            return;
        }

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop_id)
            ->whereKey($this->customer_id)
            ->first();

        if ($customer === null) {
            return;
        }

        $address = $this->savedAddressLine();

        if (blank($customer->address) && $address !== null) {
            $customer->address = mb_substr($address, 0, 255);
        }

        if (blank($customer->phone) && filled($this->delivery_phone)) {
            $customer->phone = $this->delivery_phone;
        }

        if ($customer->isDirty()) {
            $customer->save();
        }
    }

    public function savedAddressLine(): ?string
    {
        $line = collect([$this->delivery_address, $this->delivery_area])->filter(fn ($part) => filled($part))->implode(', ');

        return $line !== '' ? $line : null;
    }

    public function displayName(): string
    {
        return $this->customer?->name ?? $this->profile_name ?? '+'.$this->wa_id;
    }
}
