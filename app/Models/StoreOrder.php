<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\StoreOrderStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order from an online store, waiting for or past a person's decision. Moves no
 * stock and no money of its own: accepting one makes an ordinary sale. While it's
 * pending its quantity is only held back from what the store is told is available.
 */
class StoreOrder extends Model
{
    use BelongsToShop, BelongsToBranch;

    public const PREPAID = 'prepaid';

    public const COD = 'cod';

    protected $fillable = [
        'shop_id',
        'store_connection_id',
        'external_order_id',
        'event_id',
        'status',
        'customer_name',
        'customer_phone',
        'delivery_address',
        'delivery_area',
        'payment_method',
        'amount_paid',
        'shipping_amount',
        'discount_amount',
        'note',
        'placed_at',
        'customer_id',
        'decided_by',
        'decided_at',
        'reject_reason',
        'sale_id',
        'fulfillment_status',
        'fulfillment_updated_at',
        'cancel_flagged_at',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => StoreOrderStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'amount_paid' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'placed_at' => 'datetime',
            'decided_at' => 'datetime',
            'fulfillment_updated_at' => 'datetime',
            'cancel_flagged_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_connection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function orderNumber(): string
    {
        return 'OS-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function isDecidable(): bool
    {
        return $this->status === StoreOrderStatus::Pending;
    }

    public function isPrepaid(): bool
    {
        return $this->payment_method === self::PREPAID;
    }

    public function goodsTotal(): float
    {
        return round($this->items->sum(fn (StoreOrderItem $item) => $item->removed ? 0 : Quantity::money($item->unit_price, $item->quantity)), 2);
    }
}
