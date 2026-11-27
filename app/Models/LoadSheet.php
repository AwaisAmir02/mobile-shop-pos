<?php

namespace App\Models;

use App\Enums\LoadSheetStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One van run (W7): the orders loaded for delivery, settled at the end of the day. */
class LoadSheet extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'load_date', 'vehicle', 'driver', 'salesman_id', 'route_id', 'status', 'settled_at', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['load_date' => 'date', 'settled_at' => 'datetime', 'status' => LoadSheetStatus::class];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    /** The delivery route the van is running, if one (W7). Not route(), which reads like the URL helper. */
    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    public function number(): string
    {
        return 'LS-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return $this->status === LoadSheetStatus::Open;
    }
}
