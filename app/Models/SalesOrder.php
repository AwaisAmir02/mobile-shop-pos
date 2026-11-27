<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order booked for a customer (W7). Its stock is held for it, not taken,
 * until it is delivered, when it becomes an ordinary sale.
 */
class SalesOrder extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'customer_id', 'booker_id', 'route_id', 'load_sheet_id', 'sale_id', 'order_date', 'delivery_date', 'status', 'estimated_total', 'note', 'delivered_at', 'cancelled_at', 'cancel_reason', 'user_id'];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'delivery_date' => 'date', 'estimated_total' => 'decimal:2', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'status' => SalesOrderStatus::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booker_id');
    }

    /** The delivery route it goes out on (W7, W8). Not route(), which reads like the URL helper. */
    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    public function loadSheet(): BelongsTo
    {
        return $this->belongsTo(LoadSheet::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }

    public function number(): string
    {
        return 'SO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return $this->status === SalesOrderStatus::Booked;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            SalesOrderStatus::Booked => $this->load_sheet_id ? 'On a van' : 'Booked',
            SalesOrderStatus::Delivered => 'Delivered',
            SalesOrderStatus::Cancelled => 'Cancelled',
        };
    }
}
