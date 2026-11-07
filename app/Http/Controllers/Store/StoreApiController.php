<?php

namespace App\Http\Controllers\Store;

use App\Actions\StoreOrders;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StoreApiToken;
use App\Models\StoreConnection;
use App\Models\StoreProduct;
use App\Services\Billing\Entitlements;
use App\Support\BatchExpiry;
use App\Support\StoreAvailability;
use App\Support\StoreCatalog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/** The read side of docs/channel-api-v1.md. Runs inside AuthenticateStoreToken's shop context. */
class StoreApiController extends Controller
{
    protected const DEFAULT_LIMIT = 100;

    protected const MAX_LIMIT = 500;

    public static function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + ($fields !== [] ? ['fields' => $fields] : [])], $status);
    }

    public function me(Request $request): JsonResponse
    {
        $connection = $this->connection($request);
        $token = StoreApiToken::where('token_hash', StoreApiToken::hash((string) $request->bearerToken()))->firstOrFail();

        return response()->json(['data' => [
            'connection_id' => $connection->public_id,
            'shop_name' => Shop::whereKey($connection->shop_id)->first()?->invoiceDisplayName(),
            'status' => $connection->isLive() ? StoreConnection::ACTIVE : StoreConnection::PAUSED,
            'scopes' => $token->scopes,
            'price_sync' => $connection->price_sync,
            'server_time' => $this->time(now()),
        ]]);
    }

    public function products(Request $request): JsonResponse
    {
        if (($invalid = $this->invalidListQuery($request)) !== null) {
            return $invalid;
        }

        $connection = $this->connection($request);
        $since = $this->since($request);

        $rows = $this->rows($connection, $request)
            ->with(['product.group', 'product.mainCategory'])
            ->when($since === null, fn (Builder $query) => $query->where('published', true)->whereHas('product', fn (Builder $product) => $product->publishableOnline()))
            ->when($since !== null, fn (Builder $query) => $query->where(fn (Builder $changed) => $changed
                ->where('store_products.updated_at', '>=', $since)
                ->orWhereHas('product', fn (Builder $product) => $product
                    ->where('products.updated_at', '>=', $since)
                    ->orWhereHas('group', fn (Builder $group) => $group->where('updated_at', '>=', $since)))))
            ->limit($this->limit($request) + 1)
            ->get();

        [$page, $next] = $this->paginate($rows, $request);
        $prices = $this->prices($connection, $page);

        return response()->json([
            'data' => $page->map(fn (StoreProduct $row) => $this->productData($row, $prices))->values(),
            'meta' => ['next_cursor' => $next],
        ]);
    }

    public function product(Request $request, string $id): JsonResponse
    {
        $connection = $this->connection($request);

        $row = ctype_digit($id) ? StoreProduct::where('store_connection_id', $connection->id)
            ->where('product_id', (int) $id)
            ->with(['product.group', 'product.mainCategory'])
            ->first() : null;

        if ($row === null || ! $row->isOnSale()) {
            return self::error('not_found', 'That product doesn\'t exist or isn\'t published on this connection.', 404);
        }

        return response()->json(['data' => $this->productData($row, $this->prices($connection, new Collection([$row])))]);
    }

    public function inventory(Request $request): JsonResponse
    {
        if (($invalid = $this->invalidListQuery($request)) !== null) {
            return $invalid;
        }

        $connection = $this->connection($request);
        $since = $this->since($request);
        $sequence = StoreAvailability::nextSequence();

        $rows = $this->rows($connection, $request)
            ->with('product')
            ->where('published', true)
            ->whereHas('product', fn (Builder $product) => $product->publishableOnline())
            ->when($since !== null, fn (Builder $query) => $this->availabilityChangedSince($query, $since))
            ->limit($this->limit($request) + 1)
            ->get();

        [$page, $next] = $this->paginate($rows, $request);
        $available = StoreAvailability::for($connection, $page->pluck('product'));

        return response()->json([
            'data' => $page->map(fn (StoreProduct $row) => [
                'product_id' => $row->product_id,
                'external_sku' => $row->external_sku,
                'available' => $available[$row->product_id] ?? 0,
                'sequence' => $sequence,
            ])->values(),
            'meta' => ['next_cursor' => $next],
        ]);
    }

    public function order(Request $request, string $externalOrderId): JsonResponse
    {
        $order = StoreOrders::find($this->connection($request), $externalOrderId);

        if ($order === null) {
            return self::error('not_found', 'No order with that id on this connection.', 404);
        }

        return response()->json(['data' => [
            'external_order_id' => $order->external_order_id,
            'status' => $order->status->value,
            'fulfillment_status' => $order->fulfillment_status?->value,
            'reject_reason' => $order->reject_reason,
            'cancel_flagged' => $order->cancel_flagged_at !== null,
            'updated_at' => $this->time($order->updated_at),
        ]]);
    }

    protected function connection(Request $request): StoreConnection
    {
        return $request->attributes->get('storeConnection');
    }

    protected function rows(StoreConnection $connection, Request $request): Builder
    {
        return StoreProduct::where('store_connection_id', $connection->id)
            ->orderBy('store_products.id')
            ->when($request->query('cursor'), fn (Builder $query, string $cursor) => $query->where('store_products.id', '>', $this->decodeCursor($cursor)));
    }

    /**
     * Stock moves touch the product and the batch, and a waiting order touches
     * its order. A batch expiring moves availability with no write at all, so
     * dates are asked about too.
     */
    protected function availabilityChangedSince(Builder $query, CarbonInterface $since): Builder
    {
        $expiredFrom = $since->copy()->timezone(Entitlements::TIMEZONE)->subDay()->toDateString();

        return $query->where(fn (Builder $changed) => $changed
            ->where('store_products.updated_at', '>=', $since)
            ->orWhereHas('product', fn (Builder $product) => $product->where('products.updated_at', '>=', $since))
            ->orWhereExists(fn (QueryBuilder $batch) => $batch->from('stock_ins')
                ->whereColumn('stock_ins.product_id', 'store_products.product_id')
                ->where(fn (QueryBuilder $moved) => $moved
                    ->where('stock_ins.updated_at', '>=', $since)
                    ->orWhere(fn (QueryBuilder $expired) => $expired->where('stock_ins.expiry_date', '>=', $expiredFrom)->where('stock_ins.expiry_date', '<', BatchExpiry::today()))))
            ->orWhereExists(fn (QueryBuilder $item) => $item->from('whatsapp_order_items')
                ->join('whatsapp_orders', 'whatsapp_orders.id', '=', 'whatsapp_order_items.whatsapp_order_id')
                ->whereColumn('whatsapp_order_items.product_id', 'store_products.product_id')
                ->where('whatsapp_orders.updated_at', '>=', $since))
            ->orWhereExists(fn (QueryBuilder $item) => $item->from('store_order_items')
                ->join('store_orders', 'store_orders.id', '=', 'store_order_items.store_order_id')
                ->whereColumn('store_order_items.product_id', 'store_products.product_id')
                ->where('store_orders.updated_at', '>=', $since)));
    }

    /** @param  array<int, float>  $prices */
    protected function productData(StoreProduct $row, array $prices): array
    {
        return StoreCatalog::productData($row, $prices);
    }

    /** @return array<int, float> */
    protected function prices(StoreConnection $connection, Collection $rows): array
    {
        return StoreCatalog::prices($connection, $rows);
    }

    protected function invalidListQuery(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:64'],
            'updated_since' => ['nullable', 'string', 'max:40'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (filled($request->query('cursor')) && $this->decodeCursor((string) $request->query('cursor')) === null) {
                $validator->errors()->add('cursor', 'That cursor isn\'t valid.');
            }

            if (filled($request->query('updated_since')) && $this->since($request) === null) {
                $validator->errors()->add('updated_since', 'Use an ISO 8601 time, with the + encoded as %2B.');
            }
        });

        return $validator->fails() ? self::error('validation_failed', 'Some of the query values aren\'t valid.', 422, $validator->errors()->toArray()) : null;
    }

    protected function limit(Request $request): int
    {
        return (int) ($request->query('limit') ?: self::DEFAULT_LIMIT);
    }

    /** An unencoded + in a query string arrives as a space, an easy mistake to forgive. */
    protected function since(Request $request): ?CarbonInterface
    {
        $value = $request->query('updated_since');

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse(str_replace(' ', '+', (string) $value))->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: Collection<int, StoreProduct>, 1: ?string} */
    protected function paginate(Collection $rows, Request $request): array
    {
        $limit = $this->limit($request);
        $page = $rows->take($limit);

        return [$page, $rows->count() > $limit ? $this->encodeCursor((int) $page->last()->id) : null];
    }

    protected function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode('id:'.$id), '+/', '-_'), '=');
    }

    protected function decodeCursor(string $cursor): ?int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded !== false && preg_match('/^id:(\d{1,18})$/', $decoded, $match) ? (int) $match[1] : null;
    }

    protected function time(?CarbonInterface $time): ?string
    {
        return StoreCatalog::time($time);
    }
}
