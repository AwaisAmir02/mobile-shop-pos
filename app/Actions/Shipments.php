<?php

namespace App\Actions;

use App\Couriers\BookingRequest;
use App\Couriers\CourierException;
use App\Enums\CourierKey;
use App\Enums\FulfillmentStatus;
use App\Enums\OnlineTool;
use App\Enums\ShipmentStatus;
use App\Enums\WriteOffReason;
use App\Models\CourierConnection;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\StoreOrder;
use App\Models\WhatsAppOrder;
use App\Support\BatchExpiry;
use App\Support\PhoneNumber;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Booking a sale's parcel with the shop's own courier account, and keeping
 * it up to date. Money never moves here: a COD amount is what the courier
 * collects for the shop. For an online seller (OnlineTool::CourierSettlements)
 * the parcel is cod_tracked: the courier owes that cash, not the customer,
 * until its payout settles it (CourierSettlements). Any other shop records
 * the cash against the sale when the courier pays out, as with any payment.
 *
 * An online seller can also type in a parcel booked on the courier's own
 * portal (recordManual): no connection, so its status is moved by hand.
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
        $delivery = $sale->delivery()->first();

        [$name, $phone, $address, $area] = match (true) {
            $delivery !== null => [$delivery->name, $delivery->phone, $delivery->address, $delivery->city],
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
            'cod_tracked' => self::tracksCod($sale->shop_id),
            'status' => ShipmentStatus::Booked,
            'courier_status' => 'Booked',
            'events' => [],
            'booked_by' => $userId,
            'booked_at' => now(),
        ]);
    }

    /** Whether this shop's COD parcels are owed by the courier until paid out (O4). */
    public static function tracksCod(int $shopId): bool
    {
        return (bool) Shop::find($shopId)?->usesTool(OnlineTool::CourierSettlements);
    }

    /**
     * A parcel the shop booked on the courier's own portal, typed in with its
     * tracking number (O1). Cash to collect is what's still owed on the sale.
     *
     * @param  array{name?: ?string, phone?: ?string, address?: ?string, city?: ?string}  $delivery  blank: from the sale's delivery or customer
     */
    public static function recordManual(Sale $sale, CourierKey $courier, ?string $courierName, string $trackingNumber, array $delivery, ?int $userId): Shipment
    {
        if ($sale->isCancelled()) {
            throw ValidationException::withMessages(['parcelTracking' => 'This sale is cancelled.']);
        }

        if (Shipment::where('sale_id', $sale->id)->where('status', '!=', ShipmentStatus::Cancelled->value)->exists()) {
            throw ValidationException::withMessages(['parcelTracking' => 'This sale already has a parcel. Cancel that one first.']);
        }

        if ($courier === CourierKey::Other && blank($courierName)) {
            throw ValidationException::withMessages(['parcelCourierName' => 'Say which courier it is.']);
        }

        if (trim($trackingNumber) === '') {
            throw ValidationException::withMessages(['parcelTracking' => 'Enter the tracking number the courier gave you.']);
        }

        $prefill = self::prefill($sale);
        $name = trim((string) ($delivery['name'] ?? '')) ?: $prefill['name'];
        $phone = trim((string) ($delivery['phone'] ?? '')) ?: $prefill['phone'];
        $address = trim((string) ($delivery['address'] ?? '')) ?: $prefill['address'];
        $city = trim((string) ($delivery['city'] ?? '')) ?: $prefill['city'];
        $courierLabel = $courier === CourierKey::Other ? trim((string) $courierName) : $courier->label();

        return Shipment::create([
            'shop_id' => $sale->shop_id,
            'courier_connection_id' => null,
            'courier' => $courier,
            'courier_name' => $courier === CourierKey::Other ? mb_substr(trim((string) $courierName), 0, 60) : null,
            'sale_id' => $sale->id,
            'reference' => $sale->invoiceNumber(),
            'consignee_name' => mb_substr($name !== '' ? $name : 'Customer', 0, 100),
            'consignee_phone' => mb_substr(self::localPhone($phone), 0, 20),
            'consignee_address' => mb_substr($address, 0, 300),
            'city' => mb_substr($city, 0, 60),
            'city_name' => mb_substr($city, 0, 100),
            'cod_amount' => round($sale->amountDue(), 2),
            'cod_tracked' => self::tracksCod($sale->shop_id),
            'weight_grams' => 0,
            'pieces' => 1,
            'description' => mb_substr($prefill['description'], 0, 250),
            'tracking_number' => mb_substr(trim($trackingNumber), 0, 60),
            'status' => ShipmentStatus::Booked,
            'courier_status' => mb_substr('Booked on the '.$courierLabel.' portal', 0, 120),
            'events' => [],
            'booked_by' => $userId,
            'booked_at' => now(),
        ]);
    }

    /**
     * A marketplace order (O6): the marketplace ships it and collects from the
     * buyer, so the sale's unpaid part is held by it until its payout statement
     * settles it (CourierSettlements). The order number is the tracking number.
     */
    public static function recordMarketplace(Sale $sale, StoreOrder $order, ?int $userId): Shipment
    {
        $existing = Shipment::withoutGlobalScopes()->where('sale_id', $sale->id)->where('status', '!=', ShipmentStatus::Cancelled->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $sale->loadMissing('items');

        return Shipment::create([
            'shop_id' => $sale->shop_id,
            'courier_connection_id' => null,
            'courier' => CourierKey::Daraz,
            'sale_id' => $sale->id,
            'store_order_id' => $order->id,
            'reference' => $sale->invoiceNumber(),
            'consignee_name' => mb_substr((string) ($order->customer_name ?: 'Daraz buyer'), 0, 100),
            'consignee_phone' => mb_substr(self::localPhone((string) $order->customer_phone), 0, 20),
            'consignee_address' => mb_substr((string) $order->delivery_address, 0, 300),
            'city' => mb_substr((string) $order->delivery_area, 0, 60),
            'city_name' => mb_substr((string) $order->delivery_area, 0, 100),
            'cod_amount' => round($sale->amountDue(), 2),
            'cod_tracked' => true,
            'weight_grams' => 0,
            'pieces' => 1,
            'description' => mb_substr($sale->items->pluck('product_name')->unique()->take(3)->implode(', '), 0, 250),
            'tracking_number' => mb_substr($order->external_order_id, 0, 60),
            'status' => ShipmentStatus::InTransit,
            'courier_status' => 'With Daraz',
            'events' => [],
            'booked_by' => $userId,
            'booked_at' => now(),
        ]);
    }

    /**
     * Moves a hand-typed parcel on, as the courier's portal shows it. Never back
     * from delivered or returned, and a returned parcel is received back as any other.
     */
    public static function markManual(Shipment $shipment, ShipmentStatus $status): Shipment
    {
        if (! $shipment->isManual()) {
            throw ValidationException::withMessages(['shipment' => $shipment->courierLabel().' updates this parcel itself.']);
        }

        if ($shipment->status->isFinal() || $shipment->courier_settlement_id !== null) {
            throw ValidationException::withMessages(['shipment' => 'This parcel is already '.strtolower($shipment->status->label()).'.']);
        }

        if (in_array($status, [ShipmentStatus::Booked, ShipmentStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['shipment' => 'Choose where the parcel is now.']);
        }

        $was = $shipment->status;

        $shipment->update([
            'status' => $status,
            'courier_status' => $status->label(),
            'last_checked_at' => now(),
            'delivered_at' => $status === ShipmentStatus::Delivered ? now() : $shipment->delivered_at,
            'returned_at' => $status === ShipmentStatus::Returned ? now() : $shipment->returned_at,
        ]);

        if ($was !== $status) {
            self::followOrder($shipment);
        }

        return $shipment;
    }

    /** Asks the courier where the parcel is. Throws CourierException; the reason is kept on the shipment too. */
    public static function refresh(Shipment $shipment): Shipment
    {
        if ($shipment->status === ShipmentStatus::Cancelled || $shipment->isManual()) {
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
            throw ValidationException::withMessages(['shipment' => 'Only a parcel the courier hasn\'t picked up yet can be cancelled. Ask '.$shipment->courierLabel().' to return it instead.']);
        }

        if ($shipment->isManual()) {
            $shipment->update(['status' => ShipmentStatus::Cancelled, 'courier_status' => 'Cancelled', 'cancelled_at' => now()]);

            return;
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
     *
     * O5: what the courier charges to bring it back (kept on the parcel and
     * taken off with its payout, CourierSettlements), and goods that came back
     * damaged, written off once the sale's stock is back. Only with the sale
     * cancelled: until then the goods still count as sold.
     *
     * @param  array<int, int|float|string>  $damaged  product id => quantity
     */
    public static function receiveReturn(Shipment $shipment, bool $cancelSale, ?int $userId, ?float $returnCharge = null, array $damaged = []): void
    {
        if (! $shipment->awaitsReturnReceipt()) {
            throw ValidationException::withMessages(['shipment' => 'This parcel isn\'t waiting to be received back.']);
        }

        $damaged = array_filter($damaged, fn ($quantity) => is_numeric($quantity) && (float) $quantity > 0);

        if ($damaged !== [] && ! $cancelSale) {
            throw ValidationException::withMessages(['damaged' => 'Damaged goods can only be written off when the sale is cancelled and its stock is back.']);
        }

        if ($returnCharge !== null && ($returnCharge < 0 || $returnCharge > 100000)) {
            throw ValidationException::withMessages(['returnCharge' => 'Enter what the courier charges to return it.']);
        }

        DB::transaction(function () use ($shipment, $cancelSale, $userId, $returnCharge, $damaged) {
            $shipment->update(['return_received_at' => now()] + ($returnCharge !== null ? ['return_charge' => round($returnCharge, 2)] : []));

            $sale = $shipment->sale;

            if ($cancelSale && $sale !== null && ! $sale->isCancelled()) {
                CancelSale::handle($sale, $userId, 'Returned by '.$shipment->courierLabel().' ('.$shipment->tracking_number.')');
            }

            if ($damaged === [] || $sale === null) {
                return;
            }

            $sent = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->whereNotNull('product_id')->get()
                ->groupBy('product_id')->map(fn ($rows) => Quantity::sum($rows->pluck('quantity')));

            foreach ($damaged as $productId => $quantity) {
                if (! isset($sent[(int) $productId]) || Quantity::compare($quantity, $sent[(int) $productId]) > 0) {
                    throw ValidationException::withMessages(['damaged.'.$productId => 'That is more than went out in this parcel.']);
                }

                CreateStockWriteOff::handle(Product::withoutGlobalScopes()->findOrFail((int) $productId), $quantity, WriteOffReason::Damaged, 'Came back damaged in parcel '.$shipment->tracking_number, $userId, BatchExpiry::today());
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
