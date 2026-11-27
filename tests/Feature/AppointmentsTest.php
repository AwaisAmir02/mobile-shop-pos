<?php

namespace Tests\Feature;

use App\Actions\Appointments;
use App\Actions\CancelSale;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Bookings on the staff day calendar, the walk-in queue, and billing them. */
class AppointmentsTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('salon');
    }

    protected function book(string $time, array $services, ?int $employeeId = null, array $extra = []): Appointment
    {
        return Appointments::book(['customer_id' => $this->client->id, 'employee_id' => $employeeId ?? $this->ali->id, 'date' => '2026-11-10', 'time' => $time] + $extra, array_map(fn ($s) => ['service_item_id' => $s->id], $services), $this->shop->id, $this->owner->id);
    }

    public function test_a_booking_takes_the_services_time_and_price(): void
    {
        $appointment = $this->book('15:00', [$this->haircut, $this->facial]);

        $this->assertSame(90, $appointment->duration_minutes);
        $this->assertSame('2026-11-10 10:00:00', $appointment->starts_at->toDateTimeString(), 'Stored in UTC');
        $this->assertSame(['800.00', '2500.00'], $appointment->services->pluck('price')->map(fn ($p) => (string) $p)->all());
    }

    public function test_overlapping_bookings_for_the_same_staff_are_refused(): void
    {
        $this->book('15:00', [$this->facial]);
        $this->book('16:00', [$this->haircut]);
        $this->book('15:30', [$this->haircut], $this->sara->id);

        $this->expectException(ValidationException::class);
        $this->book('15:30', [$this->haircut]);
    }

    public function test_a_class_takes_bookings_up_to_its_places(): void
    {
        foreach (range(1, 3) as $i) {
            $this->book('07:00', [$this->yoga], $this->sara->id);
        }

        try {
            $this->book('07:00', [$this->yoga], $this->sara->id);
            $this->fail('The class is full.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('full', collect($e->errors())->flatten()->first());
        }
    }

    public function test_walk_ins_get_the_next_queue_number(): void
    {
        $a = Appointments::book(['customer_name' => 'Walk One', 'date' => '2026-11-10', 'walk_in' => true], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);
        $b = Appointments::book(['customer_name' => 'Walk Two', 'date' => '2026-11-10', 'walk_in' => true], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);

        $this->assertSame([1, 2], [$a->queue_number, $b->queue_number]);
        $this->assertSame(AppointmentStatus::Waiting, $a->status);
        $this->assertNotNull(Appointments::move($a, AppointmentStatus::InService)->starts_at);
    }

    public function test_billing_at_the_register_completes_it_and_cancelling_the_bill_reopens_it(): void
    {
        $appointment = $this->book('15:00', [$this->haircut]);

        \Livewire\Livewire::withQueryParams(['appointment' => $appointment->id])->test('sales.create')
            ->assertSet('customerId', (string) $this->client->id)
            ->call('completeSale')->assertHasNoErrors();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Completed, $appointment->status);
        $sale = Sale::findOrFail($appointment->sale_id);
        $this->assertSame($this->ali->id, $sale->items()->first()->performed_by);

        CancelSale::handle($sale, $this->owner->id, 'x');
        $this->assertSame(AppointmentStatus::InService, $appointment->fresh()->status);
    }

    public function test_the_calendar_shows_staff_columns_and_the_queue(): void
    {
        $this->book('15:00', [$this->haircut]);
        Appointments::book(['customer_name' => 'Walk One', 'date' => '2026-11-10', 'walk_in' => true], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);

        $this->get(route('appointments.index', ['date' => '2026-11-10']))->assertOk()
            ->assertSee('data-staff-column="'.$this->ali->id.'"', false)
            ->assertSee('Hina')->assertSee('Walk One')->assertSee('#1');
    }

    public function test_booking_from_the_screen(): void
    {
        Volt::test('appointments.index')
            ->set('date', '2026-11-10')
            ->call('openBooking', false, $this->ali->id, '11:00')
            ->set('customerId', (string) $this->client->id)
            ->set('services.0.service_item_id', (string) $this->facial->id)
            ->call('book')->assertHasNoErrors();

        $this->assertSame(1, Appointment::count());
    }
}
