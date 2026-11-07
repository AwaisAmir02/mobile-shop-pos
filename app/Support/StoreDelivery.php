<?php

namespace App\Support;

use App\Models\StoreConnection;
use App\Models\StoreEvent;
use App\Models\StoreProduct;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Turns noted changes into events and sends what's due. Runs from the scheduler, not
 * the queue, so a slow store can't hold up WhatsApp messages. A store's reply is never
 * kept or shown beyond its status, so this can't be used to read what's at an address.
 */
class StoreDelivery
{
    public const STOCK_ITEMS_PER_EVENT = 200;

    public const CATALOG_EVENTS_PER_RUN = 100;

    public const EVENTS_PER_CONNECTION_PER_RUN = 50;

    public const TIME_BUDGET_SECONDS = 45;

    public const TIMEOUT_SECONDS = 10;

    public const KEEP_DELIVERED_DAYS = 30;

    /** Minutes to wait after each failed attempt; the last repeats until giving up. */
    protected const BACKOFF_MINUTES = [1, 5, 15, 60, 120, 240];

    public function __construct(protected StoreCallbackGuard $guard)
    {
    }

    /** @return array{events: int, delivered: int, failed: int} */
    public function run(): array
    {
        $started = microtime(true);
        $counts = ['events' => 0, 'delivered' => 0, 'failed' => 0];

        $connections = StoreConnection::withoutGlobalScopes()
            ->whereNull('removed_at')
            ->whereNotNull('callback_url')
            ->whereNull('delivery_paused_at')
            ->get();

        foreach ($connections as $connection) {
            ApiShopContext::current()->run($connection->shop_id, function () use ($connection, $started, &$counts) {
                // Paused: the store has been told to stop, so changes wait, ready for the resume.
                if ($connection->isLive()) {
                    $counts['events'] += $this->generateStockEvents($connection) + $this->generateCatalogEvents($connection);
                }

                foreach (StoreEvent::where('store_connection_id', $connection->id)->due()->orderBy('id')->limit(self::EVENTS_PER_CONNECTION_PER_RUN)->get() as $event) {
                    if (microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                        return;
                    }

                    if ($this->send($connection, $event)) {
                        $counts['delivered']++;

                        continue;
                    }

                    // Don't keep calling a store that's down.
                    $counts['failed']++;

                    return;
                }
            });
        }

        StoreEvent::withoutGlobalScopes()->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(self::KEEP_DELIVERED_DAYS))->delete();

        return $counts;
    }

    /** The change is cleared before the figure is read, so a sale landing in between gets noted again, not lost. */
    protected function generateStockEvents(StoreConnection $connection): int
    {
        $ids = DB::table('store_changes')
            ->where('store_connection_id', $connection->id)
            ->where('kind', StoreChanges::STOCK)
            ->orderBy('product_id')
            ->limit(self::STOCK_ITEMS_PER_EVENT)
            ->pluck('product_id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($connection, $ids) {
            DB::table('store_changes')->where('store_connection_id', $connection->id)->where('kind', StoreChanges::STOCK)->whereIn('product_id', $ids)->delete();

            $sequence = StoreAvailability::nextSequence();

            $rows = StoreProduct::where('store_connection_id', $connection->id)
                ->whereIn('product_id', $ids)
                ->where('published', true)
                ->whereHas('product', fn ($product) => $product->publishableOnline())
                ->with('product')
                ->orderBy('product_id')
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $available = StoreAvailability::for($connection, $rows->pluck('product'));

            StoreEvents::record($connection, 'inventory.updated', ['items' => $rows->map(fn (StoreProduct $row) => [
                'product_id' => $row->product_id,
                'external_sku' => $row->external_sku,
                'available' => $available[$row->product_id] ?? 0,
                'sequence' => $sequence,
            ])->values()->all()]);

            return 1;
        });
    }

    protected function generateCatalogEvents(StoreConnection $connection): int
    {
        $ids = DB::table('store_changes')
            ->where('store_connection_id', $connection->id)
            ->where('kind', StoreChanges::CATALOG)
            ->orderBy('product_id')
            ->limit(self::CATALOG_EVENTS_PER_RUN)
            ->pluck('product_id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($connection, $ids) {
            DB::table('store_changes')->where('store_connection_id', $connection->id)->where('kind', StoreChanges::CATALOG)->whereIn('product_id', $ids)->delete();

            $rows = StoreProduct::where('store_connection_id', $connection->id)
                ->whereIn('product_id', $ids)
                ->with(['product.group', 'product.mainCategory'])
                ->orderBy('product_id')
                ->get();

            $onSale = $rows->filter(fn (StoreProduct $row) => $row->isOnSale());
            $prices = StoreCatalog::prices($connection, $onSale);

            foreach ($rows as $row) {
                $row->isOnSale()
                    ? StoreEvents::record($connection, 'product.updated', StoreCatalog::productData($row, $prices))
                    : StoreEvents::productUnpublished($connection, $row);
            }

            return $rows->count();
        });
    }

    protected function send(StoreConnection $connection, StoreEvent $event): bool
    {
        [$status, $error] = $this->post($connection, $event);

        $event->attempts++;
        $event->response_status = $status;

        if ($error === null) {
            $event->fill(['delivered_at' => now(), 'last_error' => null])->save();
            $connection->update(['last_delivered_at' => now(), 'last_delivery_error' => null]);

            return true;
        }

        $event->last_error = $error;

        if (now()->gte($event->give_up_at)) {
            // A day of trying: stop, and tell the owner rather than carrying on in silence.
            $event->failed_at = now();
            $connection->update(['delivery_paused_at' => now(), 'last_delivery_error' => $error]);
        } else {
            $minutes = self::BACKOFF_MINUTES[min($event->attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
            $event->next_attempt_at = now()->addMinutes($minutes);
            $connection->update(['last_delivery_error' => $error]);
        }

        $event->save();

        return false;
    }

    /** @return array{0: ?int, 1: ?string} the status, and what went wrong if anything did */
    protected function post(StoreConnection $connection, StoreEvent $event): array
    {
        $url = (string) $connection->callback_url;
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($this->guard->problemWith($url) !== null || ($address = $this->guard->safeAddressFor($host)) === null) {
            return [null, 'The address isn\'t reachable from the internet.'];
        }

        $body = $event->body();
        $timestamp = now()->timestamp;
        $pin = $host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address);

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(self::TIMEOUT_SECONDS)
                // Connect to the address that was checked, not whatever the name says a moment later.
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]])
                ->withHeaders([
                    'X-HOK-Event-Id' => $event->public_id,
                    'X-HOK-Timestamp' => (string) $timestamp,
                    'X-HOK-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $connection->signing_secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            return [null, 'Couldn\'t connect to the store.'];
        }

        return $response->successful() ? [$response->status(), null] : [$response->status(), 'The store answered with status '.$response->status().'.'];
    }
}
