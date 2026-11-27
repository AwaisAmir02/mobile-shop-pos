<?php

namespace Tests\Feature;

use App\Actions\LoadSheets;
use App\Actions\SalesOrders;
use App\Enums\LoadSheetStatus;
use App\Enums\PriceTier;
use App\Enums\SalesOrderStatus;
use App\Models\DeliveryRoute;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * Booked orders and load sheets (W7, SalesOrders + LoadSheets): an order holds
 * its stock until delivery, the van is loaded and settled, delivery is a sale.
 */
class SalesOrdersTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_a_booked_order_holds_its_stock_until_delivery(): void
    {
        $this->stock(10);
        $customer = $this->customer(['payment_terms_days' => 15]);
        $order = SalesOrders::save(null, $customer->id, [['product_id' => $this->tea->id, 'quantity' => 8, 'unit_price' => 1400, 'price_tier' => 'trade']], $this->owner->id, '2026-11-21', null, $this->owner->id);

        $this->assertSame(10, $this->tea->fresh()->stock_quantity);
        $this->assertSame(2, SalesOrders::available($this->tea->fresh()));

        try {
            SalesOrders::save(null, $customer->id, [['product_id' => $this->tea->id, 'quantity' => 3, 'unit_price' => 1400]], null, null, null, $this->owner->id);
            $this->fail('Booking past free stock must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2 of Tea 950g is free to book', collect($e->errors())->flatten()->first());
        }

        $walkIn = Livewire::test('sales.create')->call('addToCart', $this->tea->id)->set('cart.'.$this->tea->id.'.quantity', 3)->call('completeSale');
        $walkIn->assertHasErrors('cart.'.$this->tea->id.'.quantity');
        $this->assertSame(0, Sale::count());

        $line = $order->lines->first();
        $sale = SalesOrders::deliver($order, [$line->id => 6], 3000, 'cash', null, $this->owner->id);

        $this->assertSame(SalesOrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame('8400.00', $sale->total);
        $this->assertSame(5400.0, $sale->amountDue());
        $this->assertSame('2026-12-05', $sale->due_date->toDateString());
        $this->assertSame($this->owner->id, $sale->booker_id);
        $item = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertSame(['1400.00', '1400.00', PriceTier::Trade], [$item->unit_price, $item->list_price, $item->price_tier]);
        $this->assertSame(4, $this->tea->fresh()->stock_quantity);
        $this->assertSame(4, SalesOrders::available($this->tea->fresh()));
    }

    public function test_a_load_sheet_lists_what_to_load_and_settles_once_every_stop_is_done(): void
    {
        $this->stock(100);
        $route = DeliveryRoute::create(['shop_id' => $this->shop->id, 'name' => 'Shah Alam Mon', 'visit_days' => ['mon']]);
        $first = $this->customer(['name' => 'Second stop', 'business_name' => null, 'route_id' => $route->id, 'route_sequence' => 2]);
        $second = $this->customer(['name' => 'First stop', 'business_name' => null, 'route_id' => $route->id, 'route_sequence' => 1]);
        $a = SalesOrders::save(null, $first->id, [['product_id' => $this->tea->id, 'quantity' => 12, 'unit_price' => 1400, 'pack_name' => 'Carton', 'pack_size' => 12]], null, null, null, $this->owner->id);
        $b = SalesOrders::save(null, $second->id, [['product_id' => $this->tea->id, 'quantity' => 5, 'unit_price' => 1450]], null, null, null, $this->owner->id);

        Livewire::test('load-sheets.index')->set('routeId', (string) $route->id)->assertSet('orderIds', [(string) $a->id, (string) $b->id])
            ->set('vehicle', 'LES-4512')->call('create')->assertHasNoErrors();
        $sheet = \App\Models\LoadSheet::sole();

        $this->assertSame([['product' => 'Tea 950g', 'quantity' => 17]], array_map(fn ($row) => ['product' => $row['product'], 'quantity' => $row['quantity']], LoadSheets::loadingList($sheet)));
        $this->get(route('load-sheets.show', $sheet))->assertOk()->assertSeeInOrder(['1. First stop', '2. Second stop']);

        $page = Livewire::test('load-sheets.show', ['sheet' => $sheet])->call('settle')->assertHasErrors('settle');
        SalesOrders::deliver($a->fresh(), [], 0, null, null, $this->owner->id);
        $page->call('bringBack', $b->id)->call('settle')->assertHasNoErrors();

        $this->assertSame(LoadSheetStatus::Settled, $sheet->fresh()->status);
        $this->assertSame([SalesOrderStatus::Booked, null], [$b->fresh()->status, $b->fresh()->load_sheet_id]);

        $html = view('pdf.delivery-challan', ['order' => $a->fresh(['lines', 'customer', 'sale.items', 'loadSheet', 'booker']), 'shop' => $this->shop])->render();
        $this->assertStringContainsString('Delivery Challan', $html);
        $this->assertStringContainsString('LES-4512', $html);
        $sheetHtml = view('pdf.load-sheet', ['sheet' => $sheet->fresh(['deliveryRoute', 'salesman']), 'loading' => [], 'stops' => collect([$a->fresh('customer')]), 'owed' => [], 'shop' => $this->shop])->render();
        $this->assertStringContainsString('Load Sheet', $sheetHtml);
    }

    public function test_the_order_screens_book_and_deliver(): void
    {
        $this->stock(50);
        $customer = $this->customer();

        Livewire::test('sales-orders.form')->set('customerId', (string) $customer->id)->call('add', $this->tea->id)
            ->set('lines.0.quantity', '4')->call('save')->assertHasNoErrors();

        $order = SalesOrder::sole();
        $this->assertSame('5600.00', $order->estimated_total);   // trade price 1,400

        Livewire::test('sales-orders.show', ['order' => $order])->call('openDeliver')->set('collected', '5600')->call('deliver')->assertHasNoErrors();
        $this->assertSame(0.0, $order->fresh()->sale->amountDue());
    }
}
