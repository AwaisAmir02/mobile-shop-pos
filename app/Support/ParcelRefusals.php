<?php

namespace App\Support;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;

/**
 * Parcels that came back undelivered to a phone number (O5), so an order from
 * someone who refused before can be confirmed, asked for an advance, or turned
 * down before it's packed. Pakistani sellers flag from the first refusal: the
 * shop pays the courier both ways and gets nothing.
 *
 * Matched on the normalised phone (PhoneNumber::normalize), across this shop's
 * parcels only; a number is never shared with another shop.
 */
class ParcelRefusals
{
    /** @return array{count: int, last: ?\Illuminate\Support\Carbon, tracking: list<string>} */
    public static function forPhone(int $shopId, ?string $phone, ?int $exceptSaleId = null): array
    {
        $key = PhoneNumber::normalize($phone);

        if ($key === null) {
            return ['count' => 0, 'last' => null, 'tracking' => []];
        }

        $returned = Shipment::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('consignee_phone_key', $key)
            ->whereIn('status', [ShipmentStatus::Returning->value, ShipmentStatus::Returned->value])
            ->when($exceptSaleId !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('sale_id')->orWhere('sale_id', '!=', $exceptSaleId)))
            ->latest('id')
            ->get(['tracking_number', 'returned_at', 'updated_at']);

        return [
            'count' => $returned->count(),
            'last' => $returned->first()?->returned_at ?? $returned->first()?->updated_at,
            'tracking' => $returned->pluck('tracking_number')->take(5)->all(),
        ];
    }
}
