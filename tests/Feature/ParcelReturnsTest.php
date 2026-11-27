<?php

namespace Tests\Feature;

use App\Actions\CourierSettlements;
use App\Actions\Shipments;
use App\Enums\CourierKey;
use App\Enums\SaleStatus;
use App\Enums\ShipmentStatus;
use App\Enums\WriteOffReason;
use App\Models\Sale;
use App\Models\StockWriteOff;
use App\Support\CustomerDuesStatement;
use App\Support\ParcelRefusals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O5: a parcel that came back undelivered. What the courier charges to bring
 * it back goes on its next payout, goods that came back damaged are written
 * off, and a phone that refused before is flagged on the next order.
 */
class ParcelReturnsTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop();
    }

    public function test_a_returned_parcel_is_received_back_with_its_return_charge_and_damaged_goods_written_off(): void
    {
        $sale = $this->codSale(2, 250);
        $parcel = $this->parcel($sale, ShipmentStatus::Returned);

        // Coming back, the cash isn't the customer's debt either.
        $this->assertSame(0.0, CustomerDuesStatement::for($this->customerOf($sale))->total());

        Livewire::test('shipments.index')
            ->call('openReceive', $parcel->id)
            ->assertSee('Return charge')
            ->set('returnCharge', '150')
            ->set("damaged.{$this->suit->id}", '1')
            ->call('receive')
            ->assertHasNoErrors();

        $this->assertSame(SaleStatus::Cancelled, Sale::withoutGlobalScopes()->find($sale->id)->status);
        $this->assertSame('150.00', $parcel->fresh()->return_charge);
        $writeOff = StockWriteOff::sole();
        $this->assertSame(WriteOffReason::Damaged, $writeOff->reason);
        $this->assertStringContainsString($parcel->tracking_number, $writeOff->note);
        // 10 bought, 2 sent and back, 1 of them damaged.
        $this->assertSame(9, $this->suit->fresh()->stock_quantity);

        // The return charge comes off the courier's next payout.
        $line = CourierSettlements::defaultLine($parcel->fresh());
        $this->assertSame([0.0, 150.0], [$line['cod_collected'], $line['return_charge']]);
    }

    public function test_damaged_goods_need_the_sale_cancelled_and_cant_exceed_what_was_sent(): void
    {
        $parcel = $this->parcel($this->codSale(1, 250), ShipmentStatus::Returned);

        try {
            Shipments::receiveReturn($parcel, false, $this->owner->id, null, [$this->suit->id => 1]);
            $this->fail('Writing off goods that still count as sold should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('damaged', $e->errors());
        }

        try {
            Shipments::receiveReturn($parcel->fresh(), true, $this->owner->id, null, [$this->suit->id => 2]);
            $this->fail('More than went out should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('damaged.'.$this->suit->id, $e->errors());
        }

        // Refused as a whole: the sale and the parcel are as they were.
        $this->assertNull($parcel->fresh()->return_received_at);
        $this->assertSame(0, StockWriteOff::count());
    }

    public function test_refusals_are_found_by_phone_however_it_was_typed(): void
    {
        $this->parcel($this->codSale(1, 250, '0321-4445556'), ShipmentStatus::Returned);
        $this->parcel($this->codSale(1, 250, '+92 321 4445556'), ShipmentStatus::Returning);
        $this->parcel($this->codSale(1, 250, '0321 4445556'), ShipmentStatus::Delivered);
        $this->parcel($this->codSale(1, 250, '0300 0000000'), ShipmentStatus::Returned);

        $history = ParcelRefusals::forPhone($this->shop->id, '923214445556');
        $this->assertSame(2, $history['count']);
        $this->assertSame(0, ParcelRefusals::forPhone($this->shop->id, '0333 1234567')['count']);

        // Another shop never sees this shop's refusals.
        $other = \App\Models\Shop::create(['name' => 'Other', 'business_category' => 'social_seller']);
        $this->assertSame(0, ParcelRefusals::forPhone($other->id, '03214445556')['count']);
    }

    public function test_the_register_warns_about_a_number_that_refused_before(): void
    {
        $this->parcel($this->codSale(1, 250), ShipmentStatus::Returned);

        Livewire::test('sales.create')
            ->set('delivery.phone', '0321 4445556')
            ->assertSee('Refused 1 time before')
            ->set('delivery.phone', '0300 7654321')
            ->assertDontSee('Refused 1 time before');
    }

    public function test_a_typed_in_parcel_moves_on_by_hand_and_a_connected_ones_doesnt(): void
    {
        $parcel = $this->parcel($this->codSale(), ShipmentStatus::Booked);

        Livewire::test('shipments.index')->call('mark', $parcel->id, ShipmentStatus::Delivered->value)->assertHasNoErrors();
        $this->assertSame(ShipmentStatus::Delivered, $parcel->fresh()->status);
        $this->assertNotNull($parcel->fresh()->delivered_at);

        $this->expectException(ValidationException::class);
        Shipments::markManual($parcel->fresh(), ShipmentStatus::Returned);
    }

    public function test_a_parcel_is_added_from_the_sale_page_without_a_connected_courier(): void
    {
        $sale = $this->codSale();

        $this->get(route('sales.show', $sale))->assertSee('Add tracking number');

        Livewire::test('shipments.book', ['sale' => $sale])
            ->assertSee('Booked on the courier')
            ->set('manualCourier', CourierKey::PostEx->value)
            ->set('manualTracking', 'PX-1001')
            ->call('recordManual')
            ->assertHasNoErrors();

        $parcel = $sale->shipments()->sole();
        $this->assertSame(['PostEx', 'PX-1001', '3750.00', true], [$parcel->courierLabel(), $parcel->tracking_number, $parcel->cod_amount, $parcel->cod_tracked]);
    }
}
