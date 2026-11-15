<?php

namespace App\Actions;

use App\Couriers\BookingRequest;
use App\Couriers\CourierException;
use App\Enums\FulfillmentStatus;
use App\Enums\ShipmentStatus;
use App\Models\CourierConnection;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\StoreOrder;
use App\Models\WhatsAppOrder;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Booking a sale's parcel with the shop's own courier account, and keeping
 * it up to date. Money never moves here: a COD amount is what the courier
 * collects for the shop; the shop records it against the sale when the
 * courier pays out, as with any other payment.
 */
class Shipments
{
    /**
     * Delivery details to start the booking form with, from the order the
     * sale came from (WhatsApp or online store), else from its customer.
     *
     * @return array{name: string, phone: string, address: string, city: string, cod: float, description: string, whatsapp_order_id: ?int, store_order_id: ?int}
     */
    public static function prefill(Sale $sale): array
    {
        $sale->loadMissing(['customer', 'items']);
        $whatsapp = WhatsAppOrder::where('sale_id', $sale->id)->first();
        $store = $whatsapp ? null : StoreOrder::where('sale_id', $sale->id)->first();

        [$name, $phone, $address, $area] = match (true) {
            $whatsapp !== null => [$whatsapp->delivery_name, $whatsapp->delivery_phone, $whatsapp->delivery_address, $whatsapp->delivery_area],
            $store !== null => [$store->customer_name, $store->customer_phone, $store->delivery_address, $store->delivery_area],
            default => [$sale->customer?->name, $sale->customer?->phone, $sale->customer?->address, null],
        };

        return [
            'name' => (string) ($name ?: $sale->customer?->name),
            'phone' => self::localPhone((string) ($phone ?: $sale->customer?->phone)),
            'address' => trim(implode(', ', array_filter([(string) $address, (string) $area]))),
            'city' => (string) ($area ?? ''),
            // What's still owed is what the courier collects; a prepaid order has nothing to collect.
            'cod' => round($sale->amountDue(), 2),
            'description' => $sale->items->pluck('product_name')->unique()->take(3)->implode(', ').($sale->items->count() > 3 ? ' +more' : ''),
            'whatsapp_order_id' => $whatsapp?->id,
            'store_order_id' => $store?->id,
        ];
    }

    /** 03001234567, the form couriers ask for. */
    public static function localPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        return $digits !== '' ? $digits : trim($phone);
    }

    /**
     * @param  array{name: string, phone: string, address: string, city: string, city_name: string, cod: float, weight_grams: int, pieces: int, description: string, instructions?: string, whatsapp_order_id?: ?int, store_order_id?: ?int}  $details
     */
    public static function book(CourierConnection $connection, Sale $sale, array $details, ?int $userId): Shipment
    {
        if (! $connection->isActive()) {
            throw ValidationException::withMessages(['courier' => $connection->courier->label().' isn\'t connected. Connect it in Settings › Integrations › Couriers.']);
        }

        if ($sale->isCancelled()) {
            throw ValidationException::withMessages(['courier' => 'This sale is cancelled.']);
        }

        if (Shipment::where('sale_id', $sale->id)->where('status', '!=', ShipmentStatus::Cancelled->value)->exists()) {
            throw ValidationException::withMessages(['courier' => 'This sale already has a parcel booked. Cancel that one first to book again.']);
        }

        if ((float) $details['cod'] > round($sale->amountDue(), 2) + 0.009) {
            throw ValidationException::withMessages(['cod' => 'The amount to collect can\'t be more than the Rs '.number_format($sale->amountDue(), 2).' still owed on this sale.']);
        }

        $request = new BookingRequest(
            reference: $sale->invoiceNumber(),
            consigneeName: trim($details['name']),
            consigneePhone: self::localPhone($details['phone']),
            consigneeAddress: trim($details['address']),
            city: (string) $details['city'],
            cityName: (string) $details['city_name'],
            codAmount: (float) $details['cod'],
            weightGrams: (int) $details['weight_grams'],
            pieces: (int) $details['pieces'],
            description: trim($details['description']),
            instructions: trim($details['instructions'] ?? ''),
        );

        try {
            $result = $connection->courier->provider()->book($connection, $request);
        } catch (CourierException $e) {
            throw ValidationException::withMessages(['courier' => $e->getMessage()]);
        }

        // Booked at the courier now, so record it whatever else happens.
        return Shipment::create([
            'shop_id' => $sale->shop_id,
            'courier_connection_id' => $connection->id,
            'courier' => $connection->courier,
            'sale_id' => $sale->id,
            'whatsapp_order_id' => $details['whatsapp_order_id'] ?? null,
            'store_order_id' => $details['store_order_id'] ?? null,
            'reference' => $request->reference,
            'consignee_name' => $request->consigneeName,
            'consignee_phone' => $request->consigneePhone,
            'consignee_address' => $request->consigneeAddress,
            'city' => $request->city,
            'city_name' => $request->cityName,
            'cod_amount' => $request->codAmount,
            'weight_grams' => $request->weightGrams,
            'pieces' => $request->pieces,
            'description' => $request->description,
            'instructions' => $request->instructions ?: null,
            'tracking_number' => $result->trackingNumber,
            'label_url' => $result->labelUrl,
            'status' => ShipmentStatus::Booked,
            'courier_status' => 'Booked',
            'events' => [],
            'booked_by' => $userId,
            'booked_at' => now(),
        ]);
    }

    /** Asks the courier where the parcel is. Throws CourierException; the reason is kept on the shipment too. */
    public static function refresh(Shipment $shipment): Shipment
    {
        if ($shipment->status === ShipmentStatus::Cancelled) {
            return $shipment;
        }

        $connection = $shipment->connection;

        try {
            $result = $connection->courier->provider()->track($connection, $shipment);
        } catch (CourierException $e) {
            $shipment->update(['last_error' => $e->getMessage(), 'last_checked_at' => now()]);

            throw $e;
        }

        $was = $shipment->status;

        $shipment->update([
            'status' => $result->status,
            'courier_status' => mb_substr($result->courierStatus, 0, 120),
            'events' => array_slice($result->events, -50),
            'last_checked_at' => now(),
            'last_error' => null,
            'delivered_at' => $result->status === ShipmentStatus::Delivered ? ($shipment->delivered_at ?? now()) : $shipment->delivered_at,
            'returned_at' => $result->status === ShipmentStatus::Returned ? ($shipment->returned_at ?? now()) : $shipment->returned_at,
        ]);

        if ($was !== $result->status) {
            self::followOrder($shipment);
        }

        return $shipment;
    }

    /**
     * Moves the order's own delivery status forward when the parcel does,
     * without messaging the customer (the shop decides that). Never backwards.
     */
    protected static function followOrder(Shipment $shipment): void
    {
        $fulfillment = match ($shipment->status) {
            ShipmentStatus::OutForDelivery => FulfillmentStatus::OutForDelivery,
            ShipmentStatus::Delivered => FulfillmentStatus::Delivered,
            default => null,
        };

        if ($fulfillment === null) {
            return;
        }

        try {
            if ($shipment->whatsapp_order_id && ($order = $shipment->whatsappOrder) && ($order->fulfillment_status?->step() ?? 0) < $fulfillment->step()) {
                UpdateWhatsAppOrderFulfillment::handle($order, $fulfillment, (int) $shipment->booked_by, notify: false);
            }

            if ($shipment->store_order_id && ($order = $shipment->storeOrder) && ($order->fulfillment_status?->step() ?? 0) < $fulfillment->step()) {
                StoreOrders::updateFulfillment($order, $fulfillment);
            }
        } catch (ValidationException) {
            // The order was moved on by hand already; the parcel's own status is still right.
        }
    }

    /** Only before the courier has picked it up. */
    public static function cancel(Shipment $shipment): void
    {
        if ($shipment->status !== ShipmentStatus::Booked) {
            throw ValidationException::withMessages(['shipment' => 'Only a parcel the courier hasn\'t picked up yet can be cancelled. Ask '.$shipment->courier->label().' to return it instead.']);
        }

        try {
            $shipment->connection->courier->provider()->cancel($shipment->connection, $shipment);
        } catch (CourierException $e) {
            throw ValidationException::withMessages(['shipment' => $e->getMessage()]);
        }

        $shipment->update(['status' => ShipmentStatus::Cancelled, 'courier_status' => 'Cancelled', 'cancelled_at' => now()]);
    }

    /**
     * The shop has the returned parcel in hand. Optionally cancels the sale,
     * which puts the stock back into the batches it came from. Never done
     * automatically: a parcel marked returned may not be back yet.
     */
    public static function receiveReturn(Shipment $shipment, bool $cancelSale, ?int $userId): void
    {
        if (! $shipment->awaitsReturnReceipt()) {
            throw ValidationException::withMessages(['shipment' => 'This parcel isn\'t waiting to be received back.']);
        }

        DB::transaction(function () use ($shipment, $cancelSale, $userId) {
            $shipment->update(['return_received_at' => now()]);

            $sale = $shipment->sale;

            if ($cancelSale && $sale !== null && ! $sale->isCancelled()) {
                CancelSale::handle($sale, $userId, 'Returned by '.$shipment->courier->label().' ('.$shipment->tracking_number.')');
            }
        });
    }

    /** Updates every open parcel; one courier failing doesn't stop the rest. */
    public static function refreshOpen(int $limit = 200): array
    {
        $done = 0;
        $failed = 0;

        Shipment::withoutGlobalScopes()->open()->whereNotNull('tracking_number')
            ->with('connection')
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get()
            ->each(function (Shipment $shipment) use (&$done, &$failed) {
                try {
                    if (! $shipment->connection?->isActive()) {
                        return;
                    }

                    self::refresh($shipment);
                    $done++;
                } catch (Throwable) {
                    $failed++;
                }
            });

        return ['updated' => $done, 'failed' => $failed];
    }
}
