<?php

namespace App\Actions;

use App\Enums\StockHoldKind;
use App\Support\StockAvailability;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booked orders and their delivery (W7), the distributor's daily cycle: the
 * booker takes orders on the route, the van is loaded from a load sheet
 * (LoadSheets), and each order is delivered here (or brought back) and the
 * sheet settled.
 *
 * A booked order holds its stock: nothing is taken from a batch, but what is
 * free to sell is stock less every open order (StockAvailability), so the register
 * and other orders can't promise it twice. Delivery turns the order into an
 * ordinary sale through CreateSale, which is when stock, cost, dues and money
 * move, exactly as for a counter sale; nothing about sales changes.
 */
class SalesOrders
{
    /**
     * What is free to sell or book, leaving this order out: the shared figure
     * (StockAvailability), which also holds back pending WhatsApp and
     * online-store orders and expired batches.
     */
    public static function available(Product $product, ?int $exceptOrderId = null): int|float
    {
        return StockAvailability::freeFor($product, $exceptOrderId !== null ? [StockHoldKind::SalesOrder, $exceptOrderId] : null);
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
                $order = SalesOrder::create($attributes + ['order_date' => now()->toDateString(), 'status' => SalesOrderStatus::Booked, 'user_id' => $userId]);
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

            $order->update(['status' => SalesOrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255), 'load_sheet_id' => null]);
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
                    $sale += ['tier_price' => (float) $line->unit_price, 'price_tier' => $line->price_tier->value];
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

            $order->update(['status' => SalesOrderStatus::Delivered, 'sale_id' => $sale->id, 'delivered_at' => now()]);

            return $sale->fresh();
        });
    }
}
