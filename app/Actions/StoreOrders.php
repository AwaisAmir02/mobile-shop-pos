<?php

namespace App\Actions;

use App\Enums\FulfillmentStatus;
use App\Enums\LimitKey;
use App\Enums\SaleSource;
use App\Enums\StoreOrderStatus;
use App\Exceptions\UsageLimitReached;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Services\Billing\Entitlements;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An online store's orders. Nothing here consumes stock: accepting goes through
 * CreateSale like every other sale, so FIFO/FEFO, frozen costs, expiry refusals,
 * dues and the ledger all behave exactly as they do at the counter.
 */
class StoreOrders
{
    /**
     * Stored whatever the plan's limit says: the customer has already bought, so an
     * order is never dropped. The limit is enforced when someone accepts it. Sending
     * the same order again changes nothing and returns the one already held.
     *
     * @param  array<string, mixed>  $data  validated, in the shape of the contract's order.created
     */
    public static function receive(StoreConnection $connection, array $data, string $eventId): StoreOrder
    {
        $existing = self::find($connection, $data['external_order_id']);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($connection, $data, $eventId) {
                $order = new StoreOrder([
                    'store_connection_id' => $connection->id,
                    'external_order_id' => $data['external_order_id'],
                    'event_id' => $eventId,
                    'customer_name' => $data['customer']['name'] ?? null,
                    'customer_phone' => $data['customer']['phone'] ?? null,
                    'delivery_address' => $data['customer']['address'] ?? null,
                    'delivery_area' => $data['customer']['area'] ?? null,
                    'payment_method' => $data['payment']['method'],
                    'amount_paid' => $data['payment']['amount_paid'] ?? 0,
                    'shipping_amount' => $data['shipping_amount'] ?? 0,
                    'discount_amount' => $data['discount_amount'] ?? 0,
                    'note' => $data['note'] ?? null,
                    'placed_at' => Carbon::parse($data['placed_at'])->timezone(config('app.timezone')),
                ]);
                $order->shop_id = $connection->shop_id;
                $order->save();

                $byProduct = StoreProduct::where('store_connection_id', $connection->id)->whereIn('product_id', array_filter(array_column($data['items'], 'product_id')))->pluck('product_id', 'product_id');
                $bySku = StoreProduct::where('store_connection_id', $connection->id)->whereIn('external_sku', array_filter(array_column($data['items'], 'external_sku')))->pluck('product_id', 'external_sku');

                foreach ($data['items'] as $item) {
                    $order->items()->create([
                        'shop_id' => $connection->shop_id,
                        // A product this connection doesn't carry stays unmatched for a person to sort out.
                        'product_id' => $byProduct[$item['product_id'] ?? 0] ?? $bySku[$item['external_sku'] ?? ''] ?? null,
                        'external_sku' => $item['external_sku'] ?? null,
                        'product_name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of one order at the same moment; the other one won.
            return self::find($connection, $data['external_order_id']);
        }
    }

    /**
     * Before a decision it simply closes. After one a sale exists, with stock and
     * money behind it, so a person decides: this only raises a flag.
     */
    public static function cancelFromStore(StoreOrder $order, ?string $reason): StoreOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = self::lock($order);

            if ($order->status === StoreOrderStatus::Pending) {
                $order->update(['status' => StoreOrderStatus::Cancelled, 'cancel_reason' => $reason, 'decided_at' => now()]);
            } elseif ($order->status === StoreOrderStatus::Accepted && $order->cancel_flagged_at === null) {
                $order->update(['cancel_flagged_at' => now(), 'cancel_reason' => $reason]);
            }

            return $order;
        });
    }

    /** The screen's answer once someone has dealt with a store's cancel request. */
    public static function clearCancelFlag(StoreOrder $order): void
    {
        $order->update(['cancel_flagged_at' => null]);
    }

    /**
     * @param  array<int, int>  $removedItemIds  lines the shop isn't selling
     */
    public static function accept(StoreOrder $order, array $removedItemIds, float $invoiceDiscount, ?int $customerId, float $amountPaid, int $userId, ?string $paymentMethod = null, ?int $shopAccountId = null): Sale
    {
        return DB::transaction(function () use ($order, $removedItemIds, $invoiceDiscount, $customerId, $amountPaid, $userId, $paymentMethod, $shopAccountId) {
            $order = self::lock($order);

            self::assertDecidable($order);

            $shop = Shop::findOrFail($order->shop_id);

            if (! $shop->online_store_enabled) {
                throw ValidationException::withMessages(['order' => 'Online store isn\'t enabled for this shop, so this order can\'t be accepted.']);
            }

            try {
                app(Entitlements::class)->ensureCanAdd($shop, LimitKey::OnlineOrdersPerMonth);
            } catch (UsageLimitReached) {
                throw ValidationException::withMessages(['order' => 'You\'ve reached this month\'s online orders on your plan. This order is kept, and can be accepted once you have room.']);
            }

            $items = $order->items()->get();
            $keeping = $items->reject(fn (StoreOrderItem $item) => in_array($item->id, $removedItemIds, true));

            if ($keeping->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Keep at least one item on the order.']);
            }

            if (($unmatched = $keeping->firstWhere('product_id', null)) !== null) {
                throw ValidationException::withMessages(['lines' => "Choose the product for \"{$unmatched->product_name}\", or remove it."]);
            }

            if ($customerId !== null && ! Customer::where('shop_id', $order->shop_id)->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customerId' => 'Choose one of your customers.']);
            }

            // CreateSale locks products in line order, so a fixed order can't deadlock against another acceptance.
            $lines = $keeping->sortBy(['product_id', 'id'])->map(fn (StoreOrderItem $item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'discount' => 0.0,
                // What the customer was actually charged, whatever the shop's list price is.
                'custom_price' => (float) $item->unit_price,
            ])->values()->all();

            $sale = CreateSale::handle(
                lines: $lines,
                invoiceDiscount: $invoiceDiscount,
                customerId: $customerId,
                userId: $userId,
                saleDate: now()->toDateString(),
                amountPaid: $amountPaid,
                shopId: $order->shop_id,
                attributes: ['source' => SaleSource::Online->value],
                paymentMethod: $paymentMethod,
                shopAccountId: $shopAccountId,
            );

            $total = (float) $sale->total;

            if ($amountPaid > $total) {
                throw ValidationException::withMessages(['amountPaid' => 'Amount paid cannot be more than the sale total (Rs '.number_format($total, 2).').']);
            }

            if ($customerId === null && $amountPaid < $total) {
                throw ValidationException::withMessages(['customerId' => 'A customer is required when the order is not fully paid.']);
            }

            StoreOrderItem::whereIn('id', $items->pluck('id')->diff($keeping->pluck('id')))->update(['removed' => true]);

            $order->update([
                'status' => StoreOrderStatus::Accepted,
                'customer_id' => $customerId,
                'sale_id' => $sale->id,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            return $sale;
        });
    }

    public static function reject(StoreOrder $order, string $reason, int $userId): StoreOrder
    {
        return DB::transaction(function () use ($order, $reason, $userId) {
            $order = self::lock($order);

            self::assertDecidable($order);

            $order->update([
                'status' => StoreOrderStatus::Rejected,
                'reject_reason' => $reason,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            return $order;
        });
    }

    public static function updateFulfillment(StoreOrder $order, FulfillmentStatus $status): StoreOrder
    {
        return DB::transaction(function () use ($order, $status) {
            $order = self::lock($order);

            if ($order->status !== StoreOrderStatus::Accepted) {
                throw ValidationException::withMessages(['fulfillment' => 'Only accepted orders can be updated.']);
            }

            if ($order->fulfillment_status === FulfillmentStatus::Delivered) {
                throw ValidationException::withMessages(['fulfillment' => 'This order is already delivered.']);
            }

            if ($order->fulfillment_status !== null && $status->step() <= $order->fulfillment_status->step()) {
                throw ValidationException::withMessages(['fulfillment' => 'This order is already past "'.$status->label().'".']);
            }

            $order->update(['fulfillment_status' => $status, 'fulfillment_updated_at' => now()]);

            return $order;
        });
    }

    /**
     * Sets the product on a line, and remembers the store's code for it so its next
     * order matches by itself, unless another product already uses that code.
     */
    public static function mapItem(StoreOrderItem $item, Product $product): void
    {
        $order = $item->order;

        if (! $order->isDecidable() || (int) $product->shop_id !== (int) $order->shop_id) {
            throw ValidationException::withMessages(['lines' => 'That product can\'t be used on this order.']);
        }

        if (($reason = $product->blocksOnlinePublishing()) !== null) {
            throw ValidationException::withMessages(['lines' => "{$product->name} can't be sold online: {$reason}."]);
        }

        $item->update(['product_id' => $product->id]);

        if (filled($item->external_sku) && ! StoreProduct::where('store_connection_id', $order->store_connection_id)->where('external_sku', $item->external_sku)->exists()) {
            StoreConnections::setExternalSku($order->connection()->firstOrFail(), $product, $item->external_sku);
        }
    }

    public static function find(StoreConnection $connection, string $externalOrderId): ?StoreOrder
    {
        return StoreOrder::where('store_connection_id', $connection->id)->where('external_order_id', $externalOrderId)->first();
    }

    protected static function lock(StoreOrder $order): StoreOrder
    {
        return StoreOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    protected static function assertDecidable(StoreOrder $order): void
    {
        if (! $order->isDecidable()) {
            throw ValidationException::withMessages(['order' => 'This order has already been '.strtolower($order->status->label()).'.']);
        }
    }
}
