<?php

namespace Tests\Feature;

use App\Models\DeliveryRoute;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * Routes and bookers (W8): customers in visiting order, the booker on a sale,
 * and sales by booker, route and brand.
 */
class DeliveryRoutesTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_the_register_records_the_booker_and_the_report_splits_by_booker_route_and_brand(): void
    {
        $this->stock(50);
        $booker = User::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Asif Booker']);
        $route = DeliveryRoute::create(['shop_id' => $this->shop->id, 'name' => 'Route 7', 'booker_id' => $booker->id]);
        $customer = $this->customer(['route_id' => $route->id]);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->assertSet('bookerId', (string) $booker->id)
            ->call('addToCart', $this->tea->id)->set('cart.'.$this->tea->id.'.quantity', 2)->call('completeSale')->assertHasNoErrors();
        $this->assertSame($booker->id, Sale::sole()->booker_id);
        $this->get(route('sales.show', Sale::sole()))->assertSee('Asif Booker');

        $this->get(route('sales-performance.index'))->assertOk()->assertSee('Asif Booker')->assertSee('Rs 2,800.00')->assertSee('Rs 400.00');
        $this->get(route('sales-performance.index', ['by' => 'route']))->assertOk()->assertSee('Route 7');
        $this->get(route('sales-performance.index', ['by' => 'brand']))->assertOk()->assertSee('Tapal');
    }

    public function test_routes_keep_customers_in_visiting_order(): void
    {
        $route = DeliveryRoute::create(['shop_id' => $this->shop->id, 'name' => 'Route 1']);
        $a = $this->customer(['name' => 'A']);
        $b = $this->customer(['name' => 'B']);

        Livewire::test('delivery-routes.index')->set('routeId', $route->id)
            ->set('addCustomerId', (string) $a->id)->call('addCustomer')
            ->set('addCustomerId', (string) $b->id)->call('addCustomer')
            ->call('move', $b->id, -1);

        $this->assertSame([$b->id, $a->id], $route->customers()->pluck('id')->all());
    }
}
