<?php

namespace App\Actions;

use App\Enums\LoadSheetStatus;
use App\Enums\SalesOrderStatus;
use App\Models\LoadSheet;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A van's day (W7): booked orders go on a load sheet, the van is loaded from
 * its loading list, each stop is delivered (SalesOrders::deliver) or brought
 * back, and the sheet is settled once none is left booked on it. A load sheet
 * moves no stock or money itself; only delivery does.
 */
class LoadSheets
{
    /** @param  list<int>  $orderIds */
    public static function create(array $orderIds, string $loadDate, ?string $vehicle, ?string $driver, ?int $salesmanId, ?int $routeId, ?string $note, ?int $userId): LoadSheet
    {
        if ($orderIds === []) {
            throw ValidationException::withMessages(['orderIds' => 'Choose the orders going on this van.']);
        }

        return DB::transaction(function () use ($orderIds, $loadDate, $vehicle, $driver, $salesmanId, $routeId, $note, $userId) {
            $orders = SalesOrder::whereIn('id', $orderIds)->lockForUpdate()->get();

            if ($orders->contains(fn (SalesOrder $o) => ! $o->isOpen() || $o->load_sheet_id !== null)) {
                throw ValidationException::withMessages(['orderIds' => 'Some of those orders are already on a van, delivered or cancelled.']);
            }

            $sheet = LoadSheet::create([
                'load_date' => $loadDate,
                'vehicle' => filled($vehicle) ? mb_substr($vehicle, 0, 60) : null,
                'driver' => filled($driver) ? mb_substr($driver, 0, 80) : null,
                'salesman_id' => $salesmanId,
                'route_id' => $routeId,
                'status' => LoadSheetStatus::Open,
                'note' => filled($note) ? mb_substr($note, 0, 255) : null,
                'user_id' => $userId,
            ]);

            SalesOrder::whereIn('id', $orders->pluck('id'))->update(['load_sheet_id' => $sheet->id]);

            return $sheet;
        });
    }

    /** What to load: every product on the sheet's undelivered orders, totalled. */
    public static function loadingList(LoadSheet $sheet): array
    {
        return SalesOrderLine::query()
            ->whereIn('sales_order_id', $sheet->orders()->where('status', SalesOrderStatus::Booked->value)->pluck('id'))
            ->with('product')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($lines) => [
                'product' => $lines->first()->product_name,
                'quantity' => Quantity::sum($lines->pluck('quantity')),
                'pack_name' => $lines->first()->product?->pack_name,
                'pack_size' => $lines->first()->product?->pack_size,
                'unit' => $lines->first()->product?->unit,
            ])
            ->sortBy('product')
            ->values()
            ->all();
    }

    /** @return Collection<int, SalesOrder> the sheet's orders in visiting order (the customers' route order) */
    public static function stops(LoadSheet $sheet): Collection
    {
        return $sheet->orders()->with(['customer', 'sale'])->get()
            ->sortBy(fn (SalesOrder $order) => sprintf('%06d|%s', $order->customer?->route_sequence ?? 999999, $order->customer?->name))
            ->values();
    }

    /** Not delivered (shop closed, refused…): off the van, booked again for another day. */
    public static function bringBack(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->isOpen()) {
                $order->update(['load_sheet_id' => null]);
            }
        });
    }

    /** The day is done: every order on it was delivered or brought back. */
    public static function settle(LoadSheet $sheet): void
    {
        DB::transaction(function () use ($sheet) {
            $sheet = LoadSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();

            if ($sheet->orders()->where('status', SalesOrderStatus::Booked->value)->exists()) {
                throw ValidationException::withMessages(['settle' => 'Mark every order on this van delivered or not delivered first.']);
            }

            $sheet->update(['status' => LoadSheetStatus::Settled, 'settled_at' => now()]);
        });
    }
}
