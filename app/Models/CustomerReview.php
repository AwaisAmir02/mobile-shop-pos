<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReview extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'whatsapp_contact_id',
        'customer_id',
        'whatsapp_order_id',
        'rating',
        'comment',
        'display_name',
        'publish_consent',
        'consent_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'publish_consent' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOrder::class, 'whatsapp_order_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(MarketingPost::class);
    }

    public function stars(): string
    {
        return str_repeat('★', $this->rating).str_repeat('☆', max(0, 5 - $this->rating));
    }

    public function publicName(): string
    {
        $name = trim((string) $this->display_name);

        if ($name === '') {
            return 'A happy customer';
        }

        $parts = preg_split('/\s+/', $name);

        return $parts[0].(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : '');
    }

    public function isPublishable(): bool
    {
        return $this->status === 'complete' && $this->publish_consent;
    }
}
