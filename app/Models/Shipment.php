<?php

namespace App\Models;

use App\Enums\CourierKey;
use App\Enums\ShipmentStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parcel booked with a courier for one sale. The sale is the order: a
 * WhatsApp order or an online-store order becomes a sale when accepted, and
 * a Facebook/Instagram order is rung up as a sale. The order it came from,
 * if any, is kept so its delivery status can follow the parcel.
 */
class Shipment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'courier_connection_id',
        'courier',
        'sale_id',
        'whatsapp_order_id',
        'store_order_id',
        'reference',
        'consignee_name',
        'consignee_phone',
        'consignee_address',
        'city',
        'city_name',
        'cod_amount',
        'weight_grams',
        'pieces',
        'description',
        'instructions',
        'tracking_number',
        'label_url',
        'status',
        'courier_status',
        'events',
        'booked_by',
        'booked_at',
        'last_checked_at',
        'last_error',
        'delivered_at',
        'returned_at',
        'return_received_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'courier' => CourierKey::class,
            'status' => ShipmentStatus::class,
            'cod_amount' => 'decimal:2',
            'weight_grams' => 'integer',
            'pieces' => 'integer',
            'events' => 'array',
            'booked_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'return_received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CourierConnection::class, 'courier_connection_id');
    }

    /** The sale, cancelled or not: a returned parcel's sale may have been cancelled since. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScope(ExcludesCancelledSalesScope::class);
    }

    public function whatsappOrder(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOrder::class);
    }

    public function storeOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn ($status) => $status->value, ShipmentStatus::open()));
    }

    /** Back with the courier as returned, and the shop hasn't confirmed getting it back yet. */
    public function awaitsReturnReceipt(): bool
    {
        return $this->status === ShipmentStatus::Returned && $this->return_received_at === null;
    }

    public function sourceLabel(): string
    {
        return match (true) {
            $this->whatsapp_order_id !== null => 'WhatsApp order',
            $this->store_order_id !== null => 'Online store order',
            default => 'Sale',
        };
    }
}
