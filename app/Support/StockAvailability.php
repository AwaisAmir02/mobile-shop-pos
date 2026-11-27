<?php

namespace App\Support;

use App\Enums\SalesOrderStatus;
use App\Enums\StockHoldKind;
use App\Enums\StoreOrderStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Product;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StoreOrderItem;
use App\Models\WhatsAppOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What is free to sell (O3): the one calculation the WhatsApp chatbot, the
 * online store's published stock, the register and the sales-order form all
 * ask, so two buyers on two channels can't both be promised the last piece.
 *
 * Free = stock, less batches already expired (expiry-tracked shops), less what
 * every open order holds: booked sales orders (W7), confirmed WhatsApp orders
 * and online-store orders waiting for a decision. Never below zero. Only ever
 * worked out, never stored, so nothing here touches stock or batches; stock
 * leaves only when an order becomes a sale through CreateSale.
 *
 * An order being decided or edited leaves itself out ($except), or it would
 * be refused for its own hold. Stock is one pool for the whole shop, every
 * branch and channel together, so one figure per product.
 */
class StockAvailability
{
    /**
     * What open orders hold, per product and per kind, in thousandths.
     *
     * @param  list<int>  $productIds
     * @param  array{0: StockHoldKind, 1: int}|null  $except  the order to leave out
     * @return array<int, array<string, int>> product id => [kind value => thousandths]
     */
    public static function holds(array $productIds, ?array $except = null): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $holds = [];
        $add = function (StockHoldKind $kind, Collection $rows) use (&$holds) {
            foreach ($rows as $row) {
                $thousandths = Quantity::toThousandths(Quantity::normalize($row->total));

                if ($thousandths > 0) {
                    $holds[(int) $row->product_id][$kind->value] = $thousandths;
                }
            }
        };

        $leaveOut = fn (StockHoldKind $kind, string $column) => fn ($query) => $except !== null && $except[0] === $kind ? $query->where($column, '!=', $except[1]) : $query;

        $add(StockHoldKind::SalesOrder, SalesOrderLine::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->where('sales_orders.status', SalesOrderStatus::Booked->value)
            ->tap($leaveOut(StockHoldKind::SalesOrder, 'sales_orders.id'))
            ->whereIn('sales_order_lines.product_id', $productIds)
            ->groupBy('sales_order_lines.product_id')
            ->selectRaw('sales_order_lines.product_id, SUM(sales_order_lines.quantity) as total')
            ->toBase()->get());

        $add(StockHoldKind::WhatsAppOrder, WhatsAppOrderItem::withoutGlobalScopes()
            ->join('whatsapp_orders', 'whatsapp_orders.id', '=', 'whatsapp_order_items.whatsapp_order_id')
            ->where('whatsapp_orders.status', WhatsAppOrderStatus::Pending->value)
            ->tap($leaveOut(StockHoldKind::WhatsAppOrder, 'whatsapp_orders.id'))
            ->whereIn('whatsapp_order_items.product_id', $productIds)
            ->groupBy('whatsapp_order_items.product_id')
            ->selectRaw('whatsapp_order_items.product_id, SUM(whatsapp_order_items.quantity) as total')
            ->toBase()->get());

        $add(StockHoldKind::StoreOrder, StoreOrderItem::withoutGlobalScopes()
            ->join('store_orders', 'store_orders.id', '=', 'store_order_items.store_order_id')
            ->where('store_orders.status', StoreOrderStatus::Pending->value)
            ->where('store_order_items.removed', false)
            ->tap($leaveOut(StockHoldKind::StoreOrder, 'store_orders.id'))
            ->whereIn('store_order_items.product_id', $productIds)
            ->groupBy('store_order_items.product_id')
            ->selectRaw('store_order_items.product_id, SUM(store_order_items.quantity) as total')
            ->toBase()->get());

        // Parts priced on an open job card (repair workshops) wait for the job.
        $add(StockHoldKind::JobCard, \App\Models\JobCardLine::withoutGlobalScopes()
            ->join('job_cards', 'job_cards.id', '=', 'job_card_lines.job_card_id')
            ->whereIn('job_cards.status', \App\Enums\JobCardStatus::openValues())
            ->where('job_card_lines.kind', \App\Enums\JobLineKind::Part->value)
            ->tap($leaveOut(StockHoldKind::JobCard, 'job_cards.id'))
            ->whereIn('job_card_lines.product_id', $productIds)
            ->groupBy('job_card_lines.product_id')
            ->selectRaw('job_card_lines.product_id, SUM(job_card_lines.quantity) as total')
            ->toBase()->get());

        return $holds;
    }

    /**
     * What open orders hold, per product.
     *
     * @param  list<int>  $productIds
     * @param  array{0: StockHoldKind, 1: int}|null  $except
     * @return array<int, int|float>
     */
    public static function held(array $productIds, ?array $except = null): array
    {
        return array_map(fn (array $kinds) => Quantity::fromThousandths(array_sum($kinds)), self::holds($productIds, $except));
    }

    /**
     * Free to sell, for products of one shop.
     *
     * @param  Collection<int, Product>  $products
     * @param  array{0: StockHoldKind, 1: int}|null  $except
     * @return array<int, int|float> keyed by product id
     */
    public static function free(Collection $products, ?array $except = null): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id')->all();
        $expired = self::expiredThousandths((int) $products->first()->shop_id, $ids);
        $holds = self::holds($ids, $except);

        return $products->mapWithKeys(function (Product $product) use ($expired, $holds) {
            $left = Quantity::toThousandths($product->stock_quantity)
                - ($expired[$product->id] ?? 0)
                - array_sum($holds[$product->id] ?? []);

            return [$product->id => Quantity::fromThousandths(max(0, $left))];
        })->all();
    }

    /** @param  array{0: StockHoldKind, 1: int}|null  $except */
    public static function freeFor(Product $product, ?array $except = null): int|float
    {
        return self::free(collect([$product]), $except)[$product->id] ?? 0;
    }

    /**
     * Refuses selling more than is free of what open orders hold. Only a product
     * something holds is checked: the rest is CreateSale's own stock check, with
     * its own message, exactly as before.
     *
     * @param  list<array{product_id: int, quantity: mixed}>  $lines
     * @param  callable(int): string  $errorKey  product id => validation key
     */
    public static function assertFree(array $lines, callable $errorKey, ?array $except = null): void
    {
        $wanted = [];

        foreach ($lines as $line) {
            $wanted[(int) $line['product_id']] = Quantity::add($wanted[(int) $line['product_id']] ?? 0, Quantity::normalize($line['quantity']));
        }

        $holds = self::holds(array_keys($wanted), $except);

        if ($holds === []) {
            return;
        }

        $products = Product::query()->whereIn('id', array_keys($holds))->get()->keyBy('id');
        $free = self::free($products->values(), $except);

        foreach ($holds as $productId => $kinds) {
            $product = $products[$productId] ?? null;

            if ($product === null || Quantity::compare($free[$productId], $wanted[$productId]) >= 0) {
                continue;
            }

            $for = collect(array_keys($kinds))->map(fn (string $kind) => StockHoldKind::from($kind)->heldFor())->implode(' and ');

            throw ValidationException::withMessages([$errorKey($productId) => 'Only '.$product->unit->amount($free[$productId])." of {$product->name} is free to sell; ".$product->unit->amount(Quantity::fromThousandths(array_sum($kinds))).' is held for '.$for.'.']);
        }
    }

    /**
     * Batches already past their date, still counted in stock: CreateSale refuses
     * them, so they're not free to sell. Expiry-tracked shops only.
     *
     * @param  list<int>  $ids
     * @return array<int, int> thousandths
     */
    protected static function expiredThousandths(int $shopId, array $ids): array
    {
        if (! Shop::whereKey($shopId)->value('expiry_tracking_enabled')) {
            return [];
        }

        return StockIn::withoutGlobalScopes()
            ->whereIn('product_id', $ids)
            ->where('quantity_remaining', '>', 0)
            ->where('expiry_date', '<', BatchExpiry::today())
            ->selectRaw('product_id, SUM(quantity_remaining) as total')
            ->groupBy('product_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => Quantity::toThousandths(Quantity::normalize($row->total))])
            ->all();
    }
}
