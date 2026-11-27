<?php

namespace App\Actions;

use App\Enums\CourierKey;
use App\Enums\DarazOrderStatus;
use App\Enums\MarketplaceImportKind;
use App\Enums\ShipmentStatus;
use App\Enums\StoreDriver;
use App\Enums\StoreOrderStatus;
use App\Models\Customer;
use App\Models\MarketplaceImport;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Storefronts\DarazFiles;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Daraz, by file (O6), until Daraz's own API is approved for us.
 *
 * The shop has one Daraz store connection (driver daraz). Its order export goes
 * into the same order inbox as every online store (StoreOrders): grouped by order
 * number, matched by Seller SKU (an earlier mapping, or a product whose barcode is
 * that SKU). A Daraz order is already confirmed by the time it's exported, so a
 * fully matched one is accepted at once through CreateSale; one with an unmatched
 * SKU waits in Online Orders to be mapped. Importing the same file again changes
 * nothing; a later export moves parcels on (delivered, returned) or cancels.
 *
 * Daraz collects from the buyer and pays the shop weekly, less its commission,
 * payment fee, shipping fees and tax. That is a courier payout (O4): each
 * accepted order is a parcel held by Daraz (Shipments::recordMarketplace), owed
 * by Daraz, never by a customer, and the payout statement becomes one
 * CourierSettlement through CourierSettlements::record.
 */
class DarazImports
{
    /** The shop's Daraz connection, made the first time. */
    public static function connection(Shop $shop): StoreConnection
    {
        return StoreConnection::withoutGlobalScopes()->where('shop_id', $shop->id)->where('driver', StoreDriver::Daraz->value)->whereNull('removed_at')->first()
            ?? StoreConnection::create(['shop_id' => $shop->id, 'public_id' => 'chn_'.Str::lower(Str::random(16)), 'driver' => StoreDriver::Daraz, 'name' => 'Daraz', 'signing_secret' => Str::random(40)]);
    }

    /** Daraz itself, as the customer who owes for Daraz orders until it pays out. */
    public static function customer(StoreConnection $connection): Customer
    {
        $existing = $connection->setting('customer_id') ? Customer::withoutGlobalScopes()->where('shop_id', $connection->shop_id)->find($connection->setting('customer_id')) : null;

        if ($existing !== null) {
            return $existing;
        }

        $customer = Customer::create(['shop_id' => $connection->shop_id, 'name' => 'Daraz', 'business_name' => 'Daraz.pk']);
        $connection->update(['settings' => array_merge($connection->settings ?? [], ['customer_id' => $customer->id])]);

        return $customer;
    }

    // ── Orders ───────────────────────────────────────────────────────────

    public static function importOrders(Shop $shop, string $path, string $fileName, string $extension, ?int $userId): MarketplaceImport
    {
        $rows = DarazFiles::orders($path, $extension);
        $connection = self::connection($shop);
        self::mapSkus($connection, collect($rows)->pluck('seller_sku')->filter()->unique()->values()->all());

        $summary = ['new' => 0, 'accepted' => 0, 'waiting' => [], 'moved' => 0, 'cancelled' => 0, 'refused' => [], 'unmatched_skus' => []];

        foreach (collect($rows)->filter(fn ($row) => filled($row['order_number'] ?? null))->groupBy('order_number') as $number => $items) {
            try {
                self::importOrder($connection, (string) $number, $items, $userId, $summary);
            } catch (ValidationException $e) {
                $summary['refused'][(string) $number] = collect($e->errors())->flatten()->first();
            }
        }

        $summary['unmatched_skus'] = StoreOrder::where('store_connection_id', $connection->id)->where('status', StoreOrderStatus::Pending->value)
            ->with('items')->get()->flatMap(fn (StoreOrder $order) => $order->items->whereNull('product_id')->pluck('external_sku'))->filter()->unique()->values()->all();

        return MarketplaceImport::create([
            'shop_id' => $shop->id, 'store_connection_id' => $connection->id, 'kind' => MarketplaceImportKind::Orders,
            'file_name' => mb_substr($fileName, 0, 255), 'rows' => count($rows), 'summary' => $summary, 'user_id' => $userId,
        ]);
    }

    /** @param  Collection<int, array<string, string>>  $items */
    protected static function importOrder(StoreConnection $connection, string $number, Collection $items, ?int $userId, array &$summary): void
    {
        $statuses = $items->map(fn ($row) => DarazOrderStatus::fromExport($row['status'] ?? ''));
        $live = $items->filter(fn ($row) => DarazOrderStatus::fromExport($row['status'] ?? '') !== DarazOrderStatus::Cancelled);
        $status = $statuses->sortByDesc(fn (DarazOrderStatus $s) => $s->rank())->first();
        $order = StoreOrders::find($connection, $number);

        if ($order === null) {
            if ($live->isEmpty()) {
                return;
            }

            $order = StoreOrders::receiveMapped($connection, self::orderData($number, $live), 'daraz-file:'.$number);
            $summary['new']++;
        } elseif ($live->isEmpty()) {
            if ($order->status === StoreOrderStatus::Pending || ($order->status === StoreOrderStatus::Accepted && $order->cancel_flagged_at === null)) {
                StoreOrders::cancelFromStore($order, 'Cancelled on Daraz');
                $summary['cancelled']++;
            }

            return;
        }

        if ($order->status === StoreOrderStatus::Pending) {
            if ($order->items()->whereNull('product_id')->where('removed', false)->exists()) {
                $summary['waiting'][] = $number;

                return;
            }

            StoreOrders::accept($order, [], 0, self::customer($connection)->id, 0, (int) $userId);
            $summary['accepted']++;
            $order->refresh();
        }

        if ($order->status === StoreOrderStatus::Accepted && ($target = $status->shipmentStatus()) !== null) {
            $parcel = Shipment::withoutGlobalScopes()->where('store_order_id', $order->id)->where('courier', CourierKey::Daraz->value)->first();

            if ($parcel !== null && $parcel->status !== $target && ! $parcel->status->isFinal() && $parcel->courier_settlement_id === null) {
                Shipments::markManual($parcel, $target);
                $summary['moved']++;
            }
        }
    }

    /**
     * One order's rows in the contract's order shape. Daraz has been paid by the
     * buyer, and pays the shop later, so the sale is unpaid (Daraz owes it).
     *
     * @param  Collection<int, array<string, string>>  $items
     * @return array<string, mixed>
     */
    protected static function orderData(string $number, Collection $items): array
    {
        $first = $items->first();

        try {
            $placed = Carbon::parse($first['created_at'] ?? 'now', 'Asia/Karachi')->toIso8601String();
        } catch (Throwable) {
            $placed = now()->toIso8601String();
        }

        return [
            'external_order_id' => $number,
            'placed_at' => $placed,
            'customer' => [
                'name' => filled($first['customer_name'] ?? null) ? mb_substr($first['customer_name'], 0, 120) : null,
                'phone' => filled($first['phone'] ?? null) ? mb_substr($first['phone'], 0, 50) : null,
                'address' => filled($first['address'] ?? null) ? mb_substr($first['address'], 0, 500) : null,
                'area' => filled($first['city'] ?? null) ? mb_substr($first['city'], 0, 200) : null,
            ],
            'payment' => ['method' => StoreOrder::PREPAID, 'amount_paid' => 0],
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'note' => mb_substr('Daraz order '.$number.(filled($first['payment_method'] ?? null) ? ' · '.$first['payment_method'] : ''), 0, 500),
            'items' => $items->groupBy('seller_sku')->map(function (Collection $lines, string $sku) {
                $quantity = (int) $lines->sum(fn ($line) => max(1, (int) ($line['quantity'] ?? 1)));
                // What the buyer paid for the items (after Daraz vouchers), else the listed price.
                $value = $lines->sum(fn ($line) => filled($line['paid_price'] ?? null) ? DarazFiles::money($line['paid_price']) : DarazFiles::money($line['unit_price'] ?? '0') * max(1, (int) ($line['quantity'] ?? 1)));

                return [
                    'external_sku' => mb_substr($sku, 0, 100),
                    'name' => mb_substr((string) ($lines->first()['item_name'] ?? $sku), 0, 255) ?: $sku,
                    'quantity' => $quantity,
                    'unit_price' => round($value / max(1, $quantity), 2),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Seller SKUs on waiting Daraz orders that match nothing here.
     *
     * @return list<array{sku: string, name: string, orders: int}>
     */
    public static function unmatched(StoreConnection $connection): array
    {
        return StoreOrder::where('store_connection_id', $connection->id)->where('status', StoreOrderStatus::Pending->value)->with('items')->get()
            ->flatMap(fn (StoreOrder $order) => $order->items->whereNull('product_id')->where('removed', false)->map(fn ($item) => ['sku' => (string) $item->external_sku, 'name' => $item->product_name, 'order' => $order->id]))
            ->groupBy('sku')
            ->map(fn (Collection $rows, string $sku) => ['sku' => $sku, 'name' => (string) $rows->first()['name'], 'orders' => $rows->pluck('order')->unique()->count()])
            ->values()->all();
    }

    /**
     * A Seller SKU matched to a product by hand: kept for every later file, put on
     * the orders waiting for it, and those now fully matched are sold.
     *
     * @return int orders sold
     */
    public static function matchSku(StoreConnection $connection, string $sku, Product $product, ?int $userId): int
    {
        if ((int) $product->shop_id !== (int) $connection->shop_id) {
            throw ValidationException::withMessages(['sku' => 'Choose one of your own products.']);
        }

        StoreProduct::updateOrCreate(['store_connection_id' => $connection->id, 'product_id' => $product->id], ['shop_id' => $connection->shop_id, 'external_sku' => $sku, 'published' => false]);

        $waiting = StoreOrder::where('store_connection_id', $connection->id)->where('status', StoreOrderStatus::Pending->value)
            ->whereHas('items', fn ($q) => $q->whereNull('product_id')->where('external_sku', $sku))->get();
        $sold = 0;

        foreach ($waiting as $order) {
            $order->items()->whereNull('product_id')->where('external_sku', $sku)->update(['product_id' => $product->id]);

            if (! $order->items()->whereNull('product_id')->where('removed', false)->exists()) {
                try {
                    StoreOrders::accept($order->fresh(), [], 0, self::customer($connection)->id, 0, (int) $userId);
                    $sold++;
                } catch (ValidationException) {
                    // Not enough stock, say: it stays waiting, as any order does.
                }
            }
        }

        return $sold;
    }

    /** New Seller SKUs that are a product's barcode here are mapped once, for every later file. */
    protected static function mapSkus(StoreConnection $connection, array $skus): void
    {
        $known = StoreProduct::where('store_connection_id', $connection->id)->whereIn('external_sku', $skus)->pluck('external_sku')->all();
        $new = array_values(array_diff($skus, $known));

        if ($new === []) {
            return;
        }

        foreach (Product::where('shop_id', $connection->shop_id)->whereIn('barcode', $new)->whereNull('archived_at')->get(['id', 'barcode']) as $product) {
            if (! StoreProduct::where('store_connection_id', $connection->id)->where('product_id', $product->id)->exists()) {
                // Not published: nothing is ever sent to Daraz from here.
                StoreProduct::create(['shop_id' => $connection->shop_id, 'store_connection_id' => $connection->id, 'product_id' => $product->id, 'external_sku' => $product->barcode, 'published' => false]);
            }
        }
    }

    // ── Payout statements ────────────────────────────────────────────────

    /**
     * What a statement would record, before anything is written: per Daraz order,
     * what Daraz credited for the items, what it charged and the tax it took; and
     * lines for no order (other fees or credits). Orders it can't find, or that
     * aren't on a payout-ready parcel, are listed and left out.
     *
     * @return array{lines: list<array{order: string, shipment_id: int, collected: float, charges: float, returned: bool}>, tax: float, other: float, skipped: list<array{order: string, reason: string}>, file_total: float, net: float, credits_unplaced: float, rows: int}
     */
    public static function previewStatement(Shop $shop, string $path, string $extension): array
    {
        $rows = DarazFiles::statement($path, $extension);
        $connection = self::connection($shop);
        $perOrder = [];
        $tax = 0.0;
        $other = 0.0;
        $fileTotal = 0.0;

        foreach ($rows as $row) {
            $amount = DarazFiles::money($row['amount'] ?? '0');
            $fileTotal += $amount;
            $name = strtolower(trim(($row['fee_name'] ?? '').' '.($row['type'] ?? '')));
            $kind = self::lineKind($name, $amount);
            // WHT not already inside Amount is taken off on top of it.
            $extraTax = isset($row['wht']) && in_array(strtolower($row['wht_included'] ?? 'yes'), ['no', 'n', 'false', '0'], true) ? abs(DarazFiles::money($row['wht'])) : 0.0;
            $fileTotal -= $extraTax;
            $number = trim($row['order_number'] ?? '');

            if ($number === '') {
                $kind === 'tax' ? $tax += abs($amount) : $other -= $amount;
                $tax += $extraTax;

                continue;
            }

            $perOrder[$number] ??= ['item' => 0.0, 'fees' => 0.0, 'tax' => 0.0];
            match ($kind) {
                'item' => $perOrder[$number]['item'] += $amount,
                'tax' => $perOrder[$number]['tax'] += -$amount,
                default => $perOrder[$number]['fees'] += -$amount,
            };
            $perOrder[$number]['tax'] += $extraTax;
        }

        $lines = [];
        $skipped = [];
        $creditsUnplaced = 0.0;

        foreach ($perOrder as $number => $sums) {
            $parcel = Shipment::where('courier', CourierKey::Daraz->value)->where('tracking_number', (string) $number)
                ->whereHas('storeOrder', fn ($q) => $q->where('store_connection_id', $connection->id))->first();

            if ($parcel === null) {
                $skipped[] = ['order' => (string) $number, 'reason' => 'Not an accepted Daraz order here. Import the orders file first.'];

                continue;
            }

            if ($parcel->courier_settlement_id !== null) {
                $skipped[] = ['order' => (string) $number, 'reason' => 'Already on payout '.$parcel->settlement?->number().'.'];

                continue;
            }

            $collected = round(max(0.0, $sums['item']), 2);
            $charges = round($sums['fees'], 2);
            $returned = in_array($parcel->status, [ShipmentStatus::Returning, ShipmentStatus::Returned], true);

            if (! $returned && $collected <= 0 && $parcel->status !== ShipmentStatus::Delivered) {
                $skipped[] = ['order' => (string) $number, 'reason' => 'Charged, but not delivered or returned here yet. Import the latest orders file first.'];

                continue;
            }

            if ($charges < 0) {
                // More credited than charged on this order (a subsidy): it lowers the payout's other deductions.
                $other += $charges;
                $charges = 0.0;
            }

            $tax += max(0.0, $sums['tax']);
            $lines[] = ['order' => (string) $number, 'shipment_id' => $parcel->id, 'collected' => $returned ? 0.0 : $collected, 'charges' => $charges, 'returned' => $returned];
        }

        if ($other < 0) {
            $creditsUnplaced = round(-$other, 2);
            $other = 0.0;
        }

        $net = round(array_sum(array_column($lines, 'collected')) - array_sum(array_column($lines, 'charges')) - $tax - $other, 2);

        return ['lines' => $lines, 'tax' => round($tax, 2), 'other' => round($other, 2), 'skipped' => $skipped, 'file_total' => round($fileTotal, 2), 'net' => $net, 'credits_unplaced' => $creditsUnplaced, 'rows' => count($rows)];
    }

    /** item: what Daraz credits for the goods; tax: withholding/VAT/GST; fee: everything else. */
    public static function lineKind(string $name, float $amount): string
    {
        // Only lines that are a tax: "Payment Fee (incl. tax)" is still a fee.
        if (preg_match('/\b(wht|withholding)\b/', $name) || preg_match('/^(vat|gst|sales tax|income tax)\b/', $name)) {
            return 'tax';
        }

        if (preg_match('/item price|paid price|product price|order value|item charges/', $name)) {
            return 'item';
        }

        return 'fee';
    }

    /**
     * Records a previewed statement as one Daraz payout. A parcel it pays for is
     * moved to delivered first if the orders file hadn't said so yet: Daraz only
     * pays for delivered orders. A returned parcel's charges are its return charge.
     *
     * @param  array<string, mixed>  $preview  previewStatement()'s answer
     * @param  array{settlement_date: string, reference?: ?string, payment_method?: ?string, shop_account_id?: ?int, note?: ?string}  $payout
     */
    public static function recordStatement(Shop $shop, array $preview, array $payout, string $fileName, ?int $userId): MarketplaceImport
    {
        if ($preview['lines'] === []) {
            throw ValidationException::withMessages(['statementFile' => 'None of this statement\'s orders can be paid here yet.']);
        }

        $connection = self::connection($shop);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($shop, $preview, $payout, $fileName, $userId, $connection) {
            foreach ($preview['lines'] as $line) {
                $parcel = Shipment::findOrFail($line['shipment_id']);

                if (! $line['returned'] && $parcel->status !== ShipmentStatus::Delivered && ! $parcel->status->isFinal()) {
                    Shipments::markManual($parcel, ShipmentStatus::Delivered);
                }
            }

            $settlement = CourierSettlements::record($shop, CourierKey::Daraz, array_map(fn (array $line) => [
                'shipment_id' => $line['shipment_id'],
                'cod_collected' => $line['returned'] ? 0 : $line['collected'],
                'courier_charge' => $line['returned'] ? 0 : $line['charges'],
                'return_charge' => $line['returned'] ? $line['charges'] : 0,
            ], $preview['lines']), [
                'settlement_date' => $payout['settlement_date'],
                'reference' => $payout['reference'] ?? null,
                'tax_withheld' => $preview['tax'],
                'other_deductions' => $preview['other'],
                'payment_method' => $payout['payment_method'] ?? null,
                'shop_account_id' => $payout['shop_account_id'] ?? null,
                'note' => $payout['note'] ?? 'Daraz statement '.$fileName,
            ], $userId);

            return MarketplaceImport::create([
                'shop_id' => $shop->id, 'store_connection_id' => $connection->id, 'kind' => MarketplaceImportKind::Statement,
                'file_name' => mb_substr($fileName, 0, 255), 'rows' => $preview['rows'], 'courier_settlement_id' => $settlement->id,
                'summary' => collect($preview)->only(['skipped', 'file_total', 'net', 'credits_unplaced'])->all() + ['orders' => count($preview['lines'])],
                'user_id' => $userId,
            ]);
        });
    }
}
