<?php

namespace App\ReportQueries;

use App\Enums\CourierKey;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * Cash on delivery each courier holds for the shop (O4): on its way, delivered
 * and waiting for a payout, and refused parcels still to be accounted for.
 * Tracked parcels only (cod_tracked); the figures customers no longer owe.
 */
class CourierBalances
{
    /** @return Collection<int, array{courier: CourierKey, name: string, on_the_way: float, awaiting_payout: float, delivered: int, returned: int, return_charges: float}> */
    public function rows(): Collection
    {
        $open = array_map(fn (ShipmentStatus $status) => $status->value, [ShipmentStatus::Booked, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery]);
        $back = [ShipmentStatus::Returning->value, ShipmentStatus::Returned->value];

        return Shipment::query()
            ->where('cod_tracked', true)
            ->whereNull('courier_settlement_id')
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->selectRaw('courier, courier_name')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN cod_amount ELSE 0 END) as on_the_way', $open)
            ->selectRaw('SUM(CASE WHEN status = ? THEN cod_amount ELSE 0 END) as awaiting_payout', [ShipmentStatus::Delivered->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered', [ShipmentStatus::Delivered->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as returned', $back)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN COALESCE(return_charge, 0) ELSE 0 END) as return_charges', $back)
            ->groupBy('courier', 'courier_name')
            ->toBase()
            ->get()
            ->map(function ($row) {
                $courier = CourierKey::from($row->courier);

                return [
                    'courier' => $courier,
                    'name' => $courier === CourierKey::Other && filled($row->courier_name) ? $row->courier_name : $courier->label(),
                    'courier_name' => $row->courier_name,
                    'on_the_way' => round((float) $row->on_the_way, 2),
                    'awaiting_payout' => round((float) $row->awaiting_payout, 2),
                    'delivered' => (int) $row->delivered,
                    'returned' => (int) $row->returned,
                    'return_charges' => round((float) $row->return_charges, 2),
                ];
            })
            ->sortBy('name')
            ->values();
    }
}
