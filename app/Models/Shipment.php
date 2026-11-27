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
        'courier_name',
        'sale_id',
        'whatsapp_order_id',
        'store_order_id',
        'reference',
        'consignee_name',
        'consignee_phone',
        'consignee_phone_key',
        'consignee_address',
        'city',
        'city_name',
        'cod_amount',
        'cod_tracked',
        'courier_settlement_id',
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
        'return_charge',
        'review_token',
        'review_requested_at',
        'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $shipment) => $shipment->consignee_phone_key = \App\Support\PhoneNumber::normalize($shipment->consignee_phone));
    }

    protected function casts(): array
    {
        return [
            'courier' => CourierKey::class,
            'status' => ShipmentStatus::class,
            'cod_amount' => 'decimal:2',
            'cod_tracked' => 'boolean',
            'return_charge' => 'decimal:2',
            'weight_grams' => 'integer',
            'pieces' => 'integer',
            'events' => 'array',
            'booked_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'return_received_at' => 'datetime',
            'review_requested_at' => 'datetime',
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

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    /** Booked on the courier's own portal and typed in here: no connection, status moved by hand. */
    public function isManual(): bool
    {
        return $this->courier_connection_id === null;
    }

    /** The courier's name as the shop knows it ("Other" carries its own). */
    public function courierLabel(): string
    {
        return $this->courier === CourierKey::Other && filled($this->courier_name) ? $this->courier_name : $this->courier->label();
    }

    /**
     * Cash on delivery the courier holds or will hold for the shop (O4): a tracked
     * COD parcel on its way, delivered but not yet paid out, or coming back and not
     * yet in the shop's hands. While a sale has one, its unpaid part is owed by the
     * courier, not the customer, so customer dues leave it out (Sale::owedByCustomer).
     */
    public function scopeHeldByCourier(Builder $query): Builder
    {
        return $query->where('cod_tracked', true)
            ->where('cod_amount', '>', 0)
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->whereNull('return_received_at')
            ->where(fn ($q) => $q->whereNull('courier_settlement_id')
                ->orWhereIn('status', [ShipmentStatus::Returning->value, ShipmentStatus::Returned->value]));
    }

    /** Delivered with cash to collect and not on any payout yet: what the courier owes. */
    public function scopeAwaitingPayout(Builder $query): Builder
    {
        return $query->where('cod_tracked', true)->whereNull('courier_settlement_id')
            ->where('status', ShipmentStatus::Delivered->value);
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
