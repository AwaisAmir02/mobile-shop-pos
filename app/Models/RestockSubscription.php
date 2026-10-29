<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestockSubscription extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'product_id',
        'whatsapp_contact_id',
        'notified_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('notified_at')->whereNull('cancelled_at');
    }
}
