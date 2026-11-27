<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a sale goes (O1): the person, phone and address an online seller took
 * from a message or a call, kept apart from the customer's own address because
 * the same buyer can send to a sister in another city. Prefills the parcel.
 */
class SaleDelivery extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'sale_id', 'name', 'phone', 'phone_key', 'address', 'city'];

    protected static function booted(): void
    {
        static::saving(fn (self $delivery) => $delivery->phone_key = PhoneNumber::normalize($delivery->phone));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
