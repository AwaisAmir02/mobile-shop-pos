<?php

namespace App\Models;

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

    public const BOOKED = 'booked';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';

    protected $fillable = ['shop_id', 'customer_id', 'booker_id', 'route_id', 'load_sheet_id', 'sale_id', 'order_date', 'delivery_date', 'status', 'estimated_total', 'note', 'delivered_at', 'cancelled_at', 'cancel_reason', 'user_id'];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'delivery_date' => 'date', 'estimated_total' => 'decimal:2', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime'];
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

    public function route(): BelongsTo
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
        return $this->status === self::BOOKED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::BOOKED => $this->load_sheet_id ? 'On a van' : 'Booked',
            self::DELIVERED => 'Delivered',
            default => 'Cancelled',
        };
    }
}
