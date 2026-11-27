<?php

namespace Tests\Feature;

use App\Actions\DarazImports;
use App\Actions\StartLedger;
use App\Enums\CourierKey;
use App\Enums\SaleSource;
use App\Enums\ShipmentStatus;
use App\Enums\StoreDriver;
use App\Enums\StoreOrderStatus;
use App\Ledger\LedgerPoster;
use App\Models\CourierSettlement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\StoreOrder;
use App\ReportQueries\LedgerHealthReport;
use App\ReportQueries\UdhaarReport;
use App\Services\Marketing\MarketingStats;
use App\Support\MoneyFlow;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O6: Daraz orders and payouts from Seller Center files. Orders go through the
 * store inbox and CreateSale; Daraz owes for them, never a customer, until its
 * payout statement is recorded as a courier payout.
 */
class DarazImportsTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected const ORDER_HEADERS = ['Order Item Id', 'Order Number', 'Seller SKU', 'Item Name', 'Unit Price', 'Paid Price', 'Status', 'Created at', 'Customer Name', 'Shipping Address', 'Shipping City', 'Shipping Phone Number', 'Payment Method'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop('daraz_seller');
        $this->suit->update(['barcode' => 'LS-001']);
    }

    protected function orderRow(string $order, string $item, string $sku, string $status, float $paid = 3300): array
    {
        return [$item, $order, $sku, 'Lawn Suit 3pc', '3500', (string) $paid, $status, '2026-11-18 10:15', 'Daraz Buyer', 'House 9, Block C', 'Multan', '0300*****23', 'COD'];
    }

    protected function importOrders(array $rows): \App\Models\MarketplaceImport
    {
        return DarazImports::importOrders($this->shop, $this->darazFile(self::ORDER_HEADERS, $rows), 'orders.csv', 'csv', $this->owner->id);
    }

    public function test_matched_orders_are_sold_at_once_and_owed_by_daraz_not_a_customer(): void
    {
        // Lazada-style: one row per piece, so two rows are two pieces of one order.
        $import = $this->importOrders([
            $this->orderRow('DZ-1001', '1', 'LS-001', 'ready_to_ship'),
            $this->orderRow('DZ-1001', '2', 'LS-001', 'ready_to_ship'),
            $this->orderRow('DZ-1002', '3', 'LS-001', 'pending', 3400),
        ]);

        $this->assertSame([2, 2], [$import->summary['new'], $import->summary['accepted']]);
        $this->assertSame(7, $this->suit->fresh()->stock_quantity);

        $sale = Sale::where('total', 6600)->sole();
        $this->assertSame([SaleSource::Daraz, 6600.0, 'Daraz'], [$sale->source, $sale->amountDue(), $sale->customer->name]);
        $parcel = Shipment::where('sale_id', $sale->id)->sole();
        $this->assertSame([CourierKey::Daraz, 'DZ-1001', true, ShipmentStatus::InTransit], [$parcel->courier, $parcel->tracking_number, $parcel->cod_tracked, $parcel->status]);

        // Owed by Daraz: in no customer's dues, still outstanding for the shop.
        $this->assertSame(0.0, (new UdhaarReport)->totalDue());
        $this->assertSame(10000.0, (new \App\ReportQueries\SalesReport)->totalOutstanding());
    }

    public function test_the_same_file_twice_changes_nothing_and_a_later_file_moves_orders_on(): void
    {
        $rows = [$this->orderRow('DZ-1', '1', 'LS-001', 'shipped'), $this->orderRow('DZ-2', '2', 'LS-001', 'shipped'), $this->orderRow('DZ-3', '3', 'LS-001', 'pending')];
        $this->importOrders($rows);
        $again = $this->importOrders($rows);

        $this->assertSame([0, 0], [$again->summary['new'], $again->summary['accepted']]);
        $this->assertSame(3, Sale::count());

        $later = $this->importOrders([$this->orderRow('DZ-1', '1', 'LS-001', 'delivered'), $this->orderRow('DZ-2', '2', 'LS-001', 'returned'), $this->orderRow('DZ-3', '3', 'LS-001', 'canceled')]);

        $this->assertSame([2, 1], [$later->summary['moved'], $later->summary['cancelled']]);
        $status = fn (string $number) => Shipment::where('tracking_number', $number)->sole()->status;
        $this->assertSame([ShipmentStatus::Delivered, ShipmentStatus::Returned], [$status('DZ-1'), $status('DZ-2')]);
        // An accepted order cancelled on Daraz is flagged for a person, never cancelled by itself.
        $this->assertNotNull(StoreOrder::where('external_order_id', 'DZ-3')->sole()->cancel_flagged_at);
    }

    public function test_an_unknown_sku_waits_and_holds_nothing_until_matched(): void
    {
        $other = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Silk Scarf', 'price' => 900, 'stock_quantity' => 5, 'details' => []]);
        $import = $this->importOrders([$this->orderRow('DZ-77', '1', 'SCARF-RED', 'pending', 850)]);

        $this->assertSame(['DZ-77'], $import->summary['waiting']);
        $this->assertSame([['sku' => 'SCARF-RED', 'name' => 'Lawn Suit 3pc', 'orders' => 1]], DarazImports::unmatched(DarazImports::connection($this->shop)));
        $this->assertSame(0, Sale::count());

        $sold = DarazImports::matchSku(DarazImports::connection($this->shop), 'SCARF-RED', $other, $this->owner->id);

        $this->assertSame(1, $sold);
        $this->assertSame(4, $other->fresh()->stock_quantity);
        $this->assertSame([], DarazImports::unmatched(DarazImports::connection($this->shop)));
    }

    public function test_pending_daraz_orders_hold_stock_from_every_other_channel(): void
    {
        $this->importOrders([$this->orderRow('DZ-5', '1', 'NEW-SKU', 'pending')]);
        $order = StoreOrder::sole();
        DarazImports::matchSku(DarazImports::connection($this->shop), 'NEW-SKU', $this->suit, $this->owner->id);

        $this->assertSame(StoreOrderStatus::Accepted, $order->fresh()->status);
        $this->assertSame(9, StockAvailability::freeFor($this->suit->fresh()));
    }

    public function test_api_style_headers_are_read_too_and_a_wrong_file_is_refused_by_name(): void
    {
        $path = $this->darazFile(['order_number', 'order_item_id', 'sellerSku', 'name', 'paidPrice', 'status'], [['DZ-9', '1', 'LS-001', 'Lawn Suit', '3300', 'delivered']]);
        $import = DarazImports::importOrders($this->shop, $path, 'api.csv', 'csv', $this->owner->id);

        $this->assertSame(1, $import->summary['accepted']);
        $this->assertSame(ShipmentStatus::Delivered, Shipment::sole()->status);

        try {
            DarazImports::importOrders($this->shop, $this->darazFile(['Invoice', 'Amount'], [['1', '2']]), 'wrong.csv', 'csv', $this->owner->id);
            $this->fail('A file without the Daraz columns should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('"Order Number", "Seller SKU", "Status"', $e->errors()['ordersFile'][0]);
            $this->assertStringContainsString('Its first row has: Invoice, Amount', $e->errors()['ordersFile'][0]);
        }
    }

    public function test_a_payout_statement_becomes_one_courier_payout_net_of_daraz_fees_and_tax(): void
    {
        $this->importOrders([$this->orderRow('DZ-1', '1', 'LS-001', 'delivered'), $this->orderRow('DZ-2', '2', 'LS-001', 'returned', 3300), $this->orderRow('DZ-3', '3', 'LS-001', 'shipped')]);
        $moneyBefore = $this->netMoneyMoved();

        $statement = $this->darazFile(['Transaction Date', 'Transaction Type', 'Fee Name', 'Amount', 'Order No.', 'Statement'], [
            ['2026-11-22', 'Orders-Sales', 'Item Price Credit', '3300.00', 'DZ-1', 'W47'],
            ['2026-11-22', 'Orders-Lazada Fees', 'Commission', '-330.00', 'DZ-1', 'W47'],
            ['2026-11-22', 'Orders-Lazada Fees', 'Payment Fee (incl. tax)', '-74.25', 'DZ-1', 'W47'],
            ['2026-11-22', 'Orders-Taxes', 'Withholding Tax', '-66.00', 'DZ-1', 'W47'],
            ['2026-11-22', 'Orders-Logistics', 'Shipping Fee Paid by Seller', '-120.00', 'DZ-2', 'W47'],
            ['2026-11-22', 'Orders-Sales', 'Item Price Credit', '3300.00', 'DZ-404', 'W47'],
            ['2026-11-22', 'Other Services', 'Sponsored Products', '-100.00', '', 'W47'],
        ]);
        $preview = DarazImports::previewStatement($this->shop, $statement, 'csv');

        $this->assertSame(['DZ-404'], array_column($preview['skipped'], 'order'));
        $this->assertSame([['DZ-1', 3300.0, 404.25, false], ['DZ-2', 0.0, 120.0, true]], array_map(fn ($line) => [$line['order'], $line['collected'], $line['charges'], $line['returned']], $preview['lines']));
        $this->assertSame([66.0, 100.0, 2609.75], [$preview['tax'], $preview['other'], $preview['net']]);

        $import = DarazImports::recordStatement($this->shop, $preview, ['settlement_date' => '2026-11-20', 'reference' => 'W47', 'payment_method' => 'account', 'shop_account_id' => $this->bank->id], 'statement.csv', $this->owner->id);

        $settlement = CourierSettlement::findOrFail($import->courier_settlement_id);
        $this->assertSame([CourierKey::Daraz, '3300.00', '524.25', '66.00', '100.00', '2609.75'], [$settlement->courier, $settlement->cod_total, $settlement->charges_total, $settlement->tax_withheld, $settlement->other_deductions, $settlement->net_amount]);
        $this->assertSame(0.0, Sale::whereHas('shipments', fn ($q) => $q->where('tracking_number', 'DZ-1'))->sole()->amountDue());
        $this->assertSame('120.00', Shipment::where('tracking_number', 'DZ-2')->sole()->return_charge);
        $this->assertSame(2609.75, round($this->netMoneyMoved() - $moneyBefore, 2));
        // DZ-3 is still with Daraz.
        $this->assertSame(3300.0, Sale::whereHas('shipments', fn ($q) => $q->where('tracking_number', 'DZ-3'))->sole()->amountDue());
    }

    public function test_daraz_shows_on_reports_only_once_used_and_the_screen_only_for_online_sellers(): void
    {
        $stats = fn () => (new MarketingStats(now()->subMonth(), now()))->salesBySource();
        $this->assertArrayNotHasKey('daraz', $stats()->all());

        $this->importOrders([$this->orderRow('DZ-1', '1', 'LS-001', 'shipped')]);
        $this->assertSame(1, $stats()['daraz']['orders']);

        $this->get(route('daraz.index'))->assertOk()->assertSee('Payout statement');
        $retail = Shop::create(['name' => 'Corner', 'business_category' => 'kiryana']);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $this->get(route('daraz.index'))->assertNotFound();
        $this->assertSame(StoreDriver::Daraz, DarazImports::connection($this->shop)->driver);
    }

    public function test_the_ledger_balances_and_agrees_with_the_records_through_daraz_orders_and_payouts(): void
    {
        // Already started under LEDGER_FORCE_ON.
        $this->shop = $this->shop->fresh()->ledger_started_at ? $this->shop->fresh() : StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        $this->actingAs($this->owner->fresh());
        $this->importOrders([$this->orderRow('DZ-1', '1', 'LS-001', 'delivered'), $this->orderRow('DZ-2', '2', 'LS-001', 'delivered')]);
        $statement = $this->darazFile(['Fee Name', 'Amount', 'Order No.'], [['Item Price Credit', '3300', 'DZ-1'], ['Commission', '-330', 'DZ-1'], ['WHT', '-66', 'DZ-1']]);
        DarazImports::recordStatement($this->shop, DarazImports::previewStatement($this->shop, $statement, 'csv'), ['settlement_date' => '2026-11-20', 'payment_method' => 'cash'], 's.csv', $this->owner->id);

        app(LedgerPoster::class)->drain(limit: 100000);
        $this->assertNull(\App\Models\LedgerOutbox::whereNotNull('last_error')->value('last_error'));
        $this->assertSame(0, (int) \App\Models\LedgerLine::sum('amount_paise'));

        foreach ((new LedgerHealthReport($this->shop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'] ?? 0.0, abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }
}
