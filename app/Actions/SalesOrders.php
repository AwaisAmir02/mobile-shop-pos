<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\LoadSheet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booked orders and their delivery (W7), the distributor's daily cycle: the
 * booker takes orders on the route, the van is loaded from a load sheet, and
 * each order is delivered (or brought back) and settled.
 *
 * A booked order holds its stock: nothing is taken from a batch, but what is
 * free to sell is stock less every open order (reserved()), so the register
 * and other orders can't promise it twice. Delivery turns the order into an
 * ordinary sale through CreateSale, which is when stock, cost, dues and money
 * move, exactly as for a counter sale; nothing about sales changes.
 */
class SalesOrders
{
    /**
     * Pieces held for open orders, per product, leaving one order out.
     *
     * @param  list<int>  $productIds
     * @return array<int, int|float>
     */
    public static function reserved(array $productIds, ?int $exceptOrderId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        return SalesOrderLine::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->where('sales_orders.status', SalesOrder::BOOKED)
            ->when($exceptOrderId !== null, fn ($q) => $q->where('sales_orders.id', '!=', $exceptOrderId))
            ->whereIn('sales_order_lines.product_id', $productIds)
            ->groupBy('sales_order_lines.product_id')
            ->selectRaw('sales_order_lines.product_id, SUM(sales_order_lines.quantity) as held')
            ->pluck('held', 'product_id')
            ->map(fn ($held) => Quantity::normalize($held))
            ->all();
    }

    /** What is free to sell or book: stock less what open orders hold. */
    public static function available(Product $product, ?int $exceptOrderId = null): int|float
    {
        $held = self::reserved([$product->id], $exceptOrderId)[$product->id] ?? 0;

        return Quantity::normalize(max(0, (float) Quantity::subtract($product->stock_quantity, $held)));
    }

    /**
     * Books an order, or replaces the lines of one still booked.
     *
     * @param  list<array{product_id: int, quantity: mixed, unit_price: mixed, discount?: mixed, pack_name?: ?string, pack_size?: ?int, price_tier?: ?string}>  $lines
     */
    public static function save(?SalesOrder $order, int $customerId, array $lines, ?int $bookerId, ?string $deliveryDate, ?string $note, ?int $userId): SalesOrder
    {
        $lines = array_values(array_filter($lines, fn (array $line) => is_numeric($line['quantity'] ?? null) && (float) $line['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product to the order.']);
        }

        return DB::transaction(function () use ($order, $customerId, $lines, $bookerId, $deliveryDate, $note, $userId) {
            $customer = Customer::findOrFail($customerId);

            if ($order !== null) {
                $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $order->isOpen()) {
                    throw ValidationException::withMessages(['lines' => 'Only a booked order can be changed.']);
                }
            }

            $wanted = [];
            $rows = [];
            $total = 0.0;

            foreach ($lines as $i => $line) {
                $product = Product::whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
                $product->unit->assertQuantity($line['quantity'], "lines.{$i}.quantity", $product->name);
                $quantity = Quantity::normalize($line['quantity']);
                $wanted[$product->id] = Quantity::add($wanted[$product->id] ?? 0, $quantity);

                if (Quantity::compare(self::available($product, $order?->id), $wanted[$product->id]) < 0) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => 'Only '.$product->unit->amount(self::available($product, $order?->id))." of {$product->name} is free to book; the rest is in stock for other orders."]);
                }

                $price = round(max(0.0, (float) $line['unit_price']), 2);
                $discount = round(max(0.0, (float) ($line['discount'] ?? 0)), 2);
                $row = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'pack_name' => $line['pack_name'] ?? null,
                    'pack_size' => $line['pack_size'] ?? null,
                    'unit_price' => $price,
                    'price_tier' => $line['price_tier'] ?? null,
                    'discount' => $discount,
                ];
                $total += max(0.0, Quantity::money($price, $quantity) - $discount);
                $rows[] = $row;
            }

            $attributes = [
                'customer_id' => $customer->id,
                'booker_id' => $bookerId,
                'route_id' => $customer->route_id,
                'delivery_date' => $deliveryDate ?: null,
                'estimated_total' => round($total, 2),
                'note' => filled($note) ? mb_substr($note, 0, 255) : null,
            ];

            if ($order === null) {
                $order = SalesOrder::create($attributes + ['order_date' => now()->toDateString(), 'status' => SalesOrder::BOOKED, 'user_id' => $userId]);
            } else {
                $order->update($attributes);
                $order->lines()->delete();
            }

            foreach ($rows as $row) {
                $order->lines()->create($row + ['shop_id' => $order->shop_id]);
            }

            return $order->fresh('lines');
        });
    }

    public static function cancel(SalesOrder $order, string $reason): void
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['cancelReason' => 'Say why the order is cancelled.']);
        }

        DB::transaction(function () use ($order, $reason) {
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['cancelReason' => 'Only a booked order can be cancelled.']);
            }

            $order->update(['status' => SalesOrder::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255), 'load_sheet_id' => null]);
        });
    }

    /**
     * Delivered: the order becomes a sale for what was actually handed over
     * (a line can come back short, or not at all), with any money collected
     * on the doorstep and the customer's due date.
     *
     * @param  array<int, mixed>  $delivered  sales_order_line id => quantity delivered (missing = as ordered)
     */
    public static function deliver(SalesOrder $order, array $delivered, float $collected, ?string $paymentMethod, ?int $shopAccountId, ?int $userId, bool $applySchemes = false): Sale
    {
        return DB::transaction(function () use ($order, $delivered, $collected, $paymentMethod, $shopAccountId, $userId, $applySchemes) {
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['deliver' => 'This order is already '.mb_strtolower($order->statusLabel()).'.']);
            }

            $today = now()->toDateString();
            $lines = [];

            foreach ($order->lines as $line) {
                $quantity = array_key_exists($line->id, $delivered) ? $delivered[$line->id] : $line->quantity;

                if (! is_numeric($quantity) || (float) $quantity <= 0) {
                    continue;
                }

                if (Quantity::compare($quantity, $line->quantity) > 0) {
                    throw ValidationException::withMessages(["delivered.{$line->id}" => "More {$line->product_name} than was ordered."]);
                }

                $quantity = Quantity::normalize($quantity);
                $share = (float) $line->quantity > 0 ? (float) $quantity / (float) $line->quantity : 0.0;
                $sale = [
                    'product_id' => $line->product_id,
                    'quantity' => $quantity,
                    'discount' => round((float) $line->discount * $share, 2),
                    'custom_price' => $line->price_tier === null ? (float) $line->unit_price : null,
                    'pack_name' => $line->pack_name,
                    'pack_size' => $line->pack_size,
                ];

                // Agreed at the customer's trade price: it stays the list price, as at the register.
                if ($line->price_tier !== null) {
                    $sale += ['tier_price' => (float) $line->unit_price, 'price_tier' => $line->price_tier];
                }

                if ($applySchemes && ($scheme = Schemes::runningFor($line->product_id, $today)) !== null && ($free = $scheme->freeFor($quantity)) > 0) {
                    $sale += ['scheme_id' => $scheme->id, 'scheme_free' => $free];
                }

                $lines[] = $sale;
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['deliver' => 'Nothing was delivered. Use "Not delivered" to take it off the van instead.']);
            }

            $customer = $order->customer;
            $attributes = ['booker_id' => $order->booker_id];

            $sale = CreateSale::handle(
                lines: $lines,
                invoiceDiscount: 0,
                customerId: $customer->id,
                userId: $userId,
                saleDate: $today,
                amountPaid: 0,
                attributes: $attributes,
            );

            $collected = round(min(max(0.0, $collected), $sale->amountDue()), 2);

            if ($collected > 0) {
                RecordSalePayment::handle($sale, $collected, $userId, $paymentMethod, $shopAccountId);
            }

            if ($sale->fresh()->amountDue() > 0 && $customer->payment_terms_days) {
                $sale->update(['due_date' => Carbon::parse($today)->addDays($customer->payment_terms_days)->toDateString()]);
            }

            $order->update(['status' => SalesOrder::DELIVERED, 'sale_id' => $sale->id, 'delivered_at' => now()]);

            return $sale->fresh();
        });
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

    /** @param  list<int>  $orderIds */
    public static function createLoadSheet(array $orderIds, string $loadDate, ?string $vehicle, ?string $driver, ?int $salesmanId, ?int $routeId, ?string $note, ?int $userId): LoadSheet
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
                'status' => 'open',
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
            ->whereIn('sales_order_id', $sheet->orders()->where('status', SalesOrder::BOOKED)->pluck('id'))
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

    /** The day is done: every order on it was delivered or brought back. */
    public static function settle(LoadSheet $sheet): void
    {
        DB::transaction(function () use ($sheet) {
            $sheet = LoadSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();

            if ($sheet->orders()->where('status', SalesOrder::BOOKED)->exists()) {
                throw ValidationException::withMessages(['settle' => 'Mark every order on this van delivered or not delivered first.']);
            }

            $sheet->update(['status' => 'settled', 'settled_at' => now()]);
        });
    }
}
