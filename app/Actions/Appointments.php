<?php

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\ServiceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bookings and the walk-in queue, shared by salons, spas, gyms (classes and
 * personal training) and trainers. A booking takes a staff member's time from
 * its start for its length; a second booking that overlaps is refused, except
 * a class: the same service at the same start, up to the service's capacity.
 * A walk-in gets the day's next queue number and no time until it's served.
 *
 * Billing is the register's job (?appointment=ID): it fills the services and
 * the customer, and the sale it makes completes the appointment.
 */
class Appointments
{
    /**
     * @param  array{customer_id?: ?int, customer_name?: ?string, phone?: ?string, employee_id?: ?int, date: string, time?: ?string, walk_in?: bool, notes?: ?string}  $fields
     * @param  list<array{service_item_id?: ?int, name?: ?string, employee_id?: ?int, price?: mixed, duration_minutes?: mixed}>  $services
     */
    public static function book(array $fields, array $services, int $shopId, ?int $userId): Appointment
    {
        return DB::transaction(function () use ($fields, $services, $shopId, $userId) {
            $walkIn = (bool) ($fields['walk_in'] ?? false);
            $rows = self::serviceRows($services, $shopId);

            if ($rows === []) {
                throw ValidationException::withMessages(['services' => 'Add at least one service.']);
            }

            if (empty($fields['customer_id']) && trim((string) ($fields['customer_name'] ?? '')) === '') {
                throw ValidationException::withMessages(['customer' => 'Choose the customer, or type their name.']);
            }

            $employeeId = ! empty($fields['employee_id']) ? (int) $fields['employee_id'] : null;
            self::assertStaff($employeeId, $shopId);
            $duration = max(5, array_sum(array_column($rows, 'duration_minutes')));
            $date = Carbon::parse($fields['date'])->toDateString();
            $startsAt = null;
            $queue = null;

            if ($walkIn) {
                $queue = (int) Appointment::withoutGlobalScopes()->where('shop_id', $shopId)->whereDate('appointment_date', $date)->where('walk_in', true)->lockForUpdate()->max('queue_number') + 1;
            } else {
                if (empty($fields['time'])) {
                    throw ValidationException::withMessages(['time' => 'Choose a time.']);
                }

                $startsAt = Carbon::parse($date.' '.$fields['time'], 'Asia/Karachi');
                self::assertFree($shopId, $employeeId, $startsAt, $duration, $rows, null);
            }

            $appointment = new Appointment([
                'customer_id' => $fields['customer_id'] ?? null,
                'customer_name' => ! empty($fields['customer_id']) ? null : mb_substr(trim((string) $fields['customer_name']), 0, 100),
                'phone' => ! empty($fields['customer_id']) ? null : (trim((string) ($fields['phone'] ?? '')) ?: null),
                'employee_id' => $employeeId,
                'walk_in' => $walkIn,
                'appointment_date' => $date,
                'starts_at' => $startsAt?->copy()->utc(),
                'duration_minutes' => $duration,
                'queue_number' => $queue,
                'status' => $walkIn ? AppointmentStatus::Waiting : AppointmentStatus::Booked,
                'notes' => isset($fields['notes']) && trim((string) $fields['notes']) !== '' ? mb_substr(trim((string) $fields['notes']), 0, 500) : null,
                'user_id' => $userId,
            ]);
            $appointment->shop_id = $shopId;
            $appointment->save();

            foreach ($rows as $row) {
                $appointment->services()->create($row + ['shop_id' => $shopId]);
            }

            return $appointment->load('services');
        });
    }

    /** Moves a booking to another time or staff member. */
    public static function reschedule(Appointment $appointment, string $date, string $time, ?int $employeeId): Appointment
    {
        return DB::transaction(function () use ($appointment, $date, $time, $employeeId) {
            $appointment = Appointment::withoutGlobalScopes()->with('services')->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $appointment->status->isOpen()) {
                throw ValidationException::withMessages(['appointment' => 'Only an open booking can be moved.']);
            }

            self::assertStaff($employeeId, $appointment->shop_id);
            $startsAt = Carbon::parse($date.' '.$time, 'Asia/Karachi');
            $rows = $appointment->services->map(fn (AppointmentService $s) => ['service_item_id' => $s->service_item_id])->all();
            self::assertFree($appointment->shop_id, $employeeId, $startsAt, $appointment->duration_minutes, $rows, $appointment->id);

            $appointment->update(['appointment_date' => Carbon::parse($date)->toDateString(), 'starts_at' => $startsAt->copy()->utc(), 'employee_id' => $employeeId]);

            return $appointment;
        });
    }

    /** Arrived, in the chair, no-show or cancelled. Completed only comes from a bill. */
    public static function move(Appointment $appointment, AppointmentStatus $status): Appointment
    {
        if ($status === AppointmentStatus::Completed) {
            throw ValidationException::withMessages(['appointment' => 'An appointment is completed by billing it.']);
        }

        if (! $appointment->status->isOpen()) {
            throw ValidationException::withMessages(['appointment' => 'This appointment is already '.strtolower($appointment->status->label()).'.']);
        }

        $changes = ['status' => $status];

        // Served now: a walk-in's time starts when they sit down.
        if ($status === AppointmentStatus::InService && $appointment->starts_at === null) {
            $changes['starts_at'] = now();
        }

        $appointment->update($changes);

        return $appointment;
    }

    /** The register billed it (?appointment=ID). */
    public static function billed(Appointment $appointment, Sale $sale): void
    {
        $appointment = Appointment::withoutGlobalScopes()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

        if (! $appointment->status->isOpen()) {
            throw ValidationException::withMessages(['appointment' => 'Appointment '.$appointment->number().' is already '.strtolower($appointment->status->label()).'.']);
        }

        $appointment->update(['status' => AppointmentStatus::Completed, 'sale_id' => $sale->id, 'customer_id' => $appointment->customer_id ?? $sale->customer_id]);
    }

    /** Its bill was cancelled: back to being served, to bill again. */
    public static function billCancelled(Sale $sale): void
    {
        Appointment::withoutGlobalScopes()->where('sale_id', $sale->id)->update(['status' => AppointmentStatus::InService->value, 'sale_id' => null]);
    }

    /** What the register puts on the bill: one service line each, with who does it. */
    public static function billLines(Appointment $appointment): array
    {
        return $appointment->services->map(fn (AppointmentService $s) => [
            'kind' => 'service',
            'service_item_id' => $s->service_item_id,
            'name' => $s->name,
            'unit_price' => (string) (float) $s->price,
            'quantity' => 1,
            'discount' => '0',
            'performed_by' => (string) ($s->employee_id ?? $appointment->employee_id ?? ''),
        ])->all();
    }

    /** A WhatsApp reminder to send from the shop's own phone (no template is needed). */
    public static function reminderText(Appointment $appointment, string $shopName): string
    {
        $when = $appointment->starts_at?->copy()->timezone('Asia/Karachi');

        return 'Assalam o Alaikum '.$appointment->whoName().', a reminder of your appointment at '.$shopName
            .($when ? ' on '.$when->format('D d M').' at '.$when->format('g:i A') : '')
            .' for '.$appointment->servicesLabel().'. Reply if you need to change it. Thank you!';
    }

    /** @return list<array<string, mixed>> */
    protected static function serviceRows(array $services, int $shopId): array
    {
        $rows = [];

        foreach ($services as $service) {
            $item = ! empty($service['service_item_id']) ? ServiceItem::withoutGlobalScopes()->where('shop_id', $shopId)->find((int) $service['service_item_id']) : null;
            $name = trim((string) ($service['name'] ?? '')) !== '' ? trim((string) $service['name']) : $item?->name;

            if ($name === null || $name === '') {
                continue;
            }

            if (! empty($service['employee_id'])) {
                self::assertStaff((int) $service['employee_id'], $shopId);
            }

            $rows[] = [
                'service_item_id' => $item?->id,
                'name' => mb_substr($name, 0, 255),
                'employee_id' => ! empty($service['employee_id']) ? (int) $service['employee_id'] : null,
                'price' => round(isset($service['price']) && $service['price'] !== '' ? (float) $service['price'] : (float) ($item?->price ?? 0), 2),
                'duration_minutes' => max(5, (int) (isset($service['duration_minutes']) && $service['duration_minutes'] !== '' ? $service['duration_minutes'] : ($item?->duration_minutes ?? 30))),
            ];
        }

        return $rows;
    }

    protected static function assertStaff(?int $employeeId, int $shopId): void
    {
        if ($employeeId !== null && ! Employee::withoutGlobalScopes()->where('shop_id', $shopId)->where('is_active', true)->whereKey($employeeId)->exists()) {
            throw ValidationException::withMessages(['employee' => 'Choose one of your staff.']);
        }
    }

    /**
     * Refuses a booking that overlaps another of the same staff member's,
     * unless both are the same class at the same start with room left.
     */
    protected static function assertFree(int $shopId, ?int $employeeId, Carbon $startsAt, int $duration, array $rows, ?int $ignoreId): void
    {
        if ($employeeId === null) {
            return;
        }

        $start = $startsAt->copy()->utc();
        $end = $start->copy()->addMinutes($duration);

        $clashes = Appointment::withoutGlobalScopes()
            ->with('services')
            ->where('shop_id', $shopId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', AppointmentStatus::openValues())
            ->whereNotNull('starts_at')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $end)
            ->get()
            ->filter(fn (Appointment $a) => $a->endsAt()->gt($start));

        if ($clashes->isEmpty()) {
            return;
        }

        // A class: everyone booked at the same start for the same one service, up to its capacity.
        $serviceIds = array_values(array_filter(array_column($rows, 'service_item_id')));
        $class = count($serviceIds) === 1 ? ServiceItem::withoutGlobalScopes()->find($serviceIds[0]) : null;

        if ($class !== null && $class->capacity > 1) {
            $sameClass = $clashes->every(fn (Appointment $a) => $a->starts_at->eq($start) && $a->services->count() === 1 && $a->services->first()->service_item_id === $class->id);

            if ($sameClass && $clashes->count() < $class->capacity) {
                return;
            }

            if ($sameClass) {
                throw ValidationException::withMessages(['time' => $class->name.' is full at that time ('.$class->capacity.' places).']);
            }
        }

        $first = $clashes->first();
        $at = $first->starts_at->copy()->timezone('Asia/Karachi');

        throw ValidationException::withMessages(['time' => 'That overlaps '.$first->whoName().'\'s booking at '.$at->format('g:i A').' ('.$first->duration_minutes.' min).']);
    }
}
