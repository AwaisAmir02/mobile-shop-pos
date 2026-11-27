<?php

use App\Actions\Appointments;
use App\Actions\CustomerDeposits;
use App\Enums\AppointmentStatus;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ServiceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Appointments (Services group): the day's bookings in a column per staff
 * member, the walk-in queue beside them, and booking, moving and billing.
 * Billing opens New Sale with the booking's services (?appointment=ID).
 */
new #[Layout('layouts.app')] #[Title('Appointments')] class extends Component
{
    use PicksPaymentMethod, Toasts;

    #[Url(except: '')]
    public string $date = '';

    public bool $walkIn = false;
    public string $customerId = '';
    public string $customerName = '';
    public string $phone = '';
    public string $employeeId = '';
    public string $time = '';
    public string $notes = '';
    /** @var list<array{service_item_id: string, employee_id: string}> */
    public array $services = [['service_item_id' => '', 'employee_id' => '']];

    public ?int $movingId = null;
    public string $moveDate = '';
    public string $moveTime = '';
    public string $moveEmployeeId = '';

    public ?int $depositFor = null;
    public string $depositAmount = '';

    public function mount(): void
    {
        $this->date = $this->date !== '' ? $this->date : now('Asia/Karachi')->toDateString();
        $this->paymentMethod = 'cash';
    }

    public function day(int $step): void
    {
        $this->date = Carbon::parse($this->date)->addDays($step)->toDateString();
    }

    public function openBooking(bool $walkIn = false, ?int $employeeId = null, ?string $time = null): void
    {
        $this->resetErrorBag();
        $this->reset(['customerId', 'customerName', 'phone', 'notes']);
        $this->walkIn = $walkIn;
        $this->employeeId = (string) ($employeeId ?? '');
        $this->time = $time ?? '';
        $this->services = [['service_item_id' => '', 'employee_id' => '']];
        $this->dispatch('open-modal', name: 'booking-form');
    }

    public function addServiceRow(): void
    {
        $this->services[] = ['service_item_id' => '', 'employee_id' => ''];
    }

    public function updatedCustomerId(): void
    {
        if ($this->customerId === '__create__') {
            $this->customerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');
        }
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId): void
    {
        $this->customerId = (string) $customerId;
    }

    public function book(): void
    {
        $this->validate(['time' => [$this->walkIn ? 'nullable' : 'required', 'nullable', 'date_format:H:i']], ['time.required' => 'Choose a time.']);
        Appointments::book([
            'customer_id' => $this->customerId !== '' ? (int) $this->customerId : null,
            'customer_name' => $this->customerName,
            'phone' => $this->phone,
            'employee_id' => $this->employeeId !== '' ? (int) $this->employeeId : null,
            'date' => $this->date,
            'time' => $this->time,
            'walk_in' => $this->walkIn,
            'notes' => $this->notes,
        ], array_map(fn ($row) => ['service_item_id' => $row['service_item_id'] !== '' ? (int) $row['service_item_id'] : null, 'employee_id' => $row['employee_id'] !== '' ? (int) $row['employee_id'] : null], $this->services), Auth::user()->shop_id, Auth::id());

        $this->dispatch('close-modal', name: 'booking-form');
        $this->toastSuccess($this->walkIn ? 'Added to the queue.' : 'Booked.');
    }

    public function setStatus(int $id, string $status): void
    {
        Appointments::move(Appointment::findOrFail($id), AppointmentStatus::from($status));
    }

    public function openMove(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $this->movingId = $appointment->id;
        $local = $appointment->starts_at?->copy()->timezone('Asia/Karachi');
        $this->moveDate = $local?->toDateString() ?? $this->date;
        $this->moveTime = $local?->format('H:i') ?? '';
        $this->moveEmployeeId = (string) ($appointment->employee_id ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'move-form');
    }

    public function move(): void
    {
        $this->validate(['moveDate' => ['required', 'date'], 'moveTime' => ['required', 'date_format:H:i']]);
        Appointments::reschedule(Appointment::findOrFail($this->movingId), $this->moveDate, $this->moveTime, $this->moveEmployeeId !== '' ? (int) $this->moveEmployeeId : null);
        $this->dispatch('close-modal', name: 'move-form');
        $this->toastSuccess('Moved.');
    }

    public function openDeposit(int $id): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Deposits), 404);
        $this->depositFor = $id;
        $this->depositAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'deposit-form');
    }

    public function takeDeposit(): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Deposits), 404);
        $appointment = Appointment::findOrFail($this->depositFor);

        if ($appointment->customer_id === null) {
            $this->addError('depositAmount', 'A deposit needs a saved customer. Edit the booking to choose one.');

            return;
        }

        [$method, $account] = $this->chosenPaymentMethod();
        CustomerDeposits::receive($appointment->customer, (float) $this->depositAmount, now('Asia/Karachi')->toDateString(), $method, $account, Auth::id(), appointment: $appointment);
        $this->dispatch('close-modal', name: 'deposit-form');
        $this->toastSuccess('Deposit received.');
    }

    public function with(): array
    {
        $day = Carbon::parse($this->date);
        $appointments = Appointment::query()->with(['services', 'customer', 'employee', 'deposits'])->whereDate('appointment_date', $day->toDateString())->orderBy('starts_at')->orderBy('queue_number')->get();
        $staff = Employee::query()->where('is_active', true)->where('takes_appointments', true)->orderBy('name')->get();
        $booked = $appointments->where('walk_in', false);
        $times = $booked->map(fn ($a) => $a->starts_at?->copy()->timezone('Asia/Karachi'))->filter();
        $open = min(9, $times->min()?->hour ?? 9);
        $close = max(21, ($times->map(fn ($t) => $t->hour + 1)->max() ?? 21));

        return [
            'day' => $day,
            'staff' => $staff,
            'columns' => $staff->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->push(['id' => null, 'name' => 'Anyone']),
            'booked' => $booked,
            'queue' => $appointments->where('walk_in', true),
            'hours' => range($open, $close - 1),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'menu' => ServiceItem::query()->where('is_active', true)->orderBy('name')->get(),
            'allStaff' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'walkInsOn' => Auth::user()->shop->usesTool(ServiceTradeTool::WalkIns),
            'depositsOn' => Auth::user()->shop->usesTool(ServiceTradeTool::Deposits),
            'paymentAccounts' => $this->paymentAccounts(),
            'shopName' => Auth::user()->shop->invoiceDisplayName(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Bookings by staff member, and the walk-in queue">Appointments</x-ui.page-header>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <x-ui.button size="sm" variant="secondary" wire:click="day(-1)" aria-label="Day before">‹</x-ui.button>
            <x-ui.input wire:model.live="date" type="date" class="w-44" aria-label="Day" />
            <x-ui.button size="sm" variant="secondary" wire:click="day(1)" aria-label="Day after">›</x-ui.button>
            <span class="text-sm font-semibold text-ink">{{ $day->format('l') }}</span>
        </div>
        <div class="flex gap-2">
            @if ($walkInsOn)
                <x-ui.button variant="secondary" wire:click="openBooking(true)" data-new-walk-in>Walk-in</x-ui.button>
            @endif
            <x-ui.button wire:click="openBooking(false)" data-new-booking>New Booking</x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-4">
        <div class="xl:col-span-3">
            <x-ui.card :padding="false" data-day-calendar>
                <div class="overflow-x-auto">
                    <div class="grid min-w-[640px]" style="grid-template-columns: 64px repeat({{ $columns->count() }}, minmax(140px, 1fr));">
                        <div class="border-b border-line bg-surface-sunken/60 px-2 py-2"></div>
                        @foreach ($columns as $column)
                            <div class="border-b border-l border-line bg-surface-sunken/60 px-3 py-2 text-sm font-semibold text-ink" data-staff-column="{{ $column['id'] ?? 'any' }}">{{ $column['name'] }}</div>
                        @endforeach
                        @foreach ($hours as $hour)
                            <div class="border-b border-line px-2 py-2 text-xs text-ink-faint">{{ \Carbon\Carbon::createFromTime($hour)->format('g A') }}</div>
                            @foreach ($columns as $column)
                                @php($slot = $booked->filter(fn ($a) => $a->employee_id === $column['id'] && $a->starts_at?->copy()->timezone('Asia/Karachi')->hour === $hour))
                                <div class="min-h-[56px] space-y-1 border-b border-l border-line p-1" wire:key="slot-{{ $hour }}-{{ $column['id'] ?? 'any' }}">
                                    @foreach ($slot as $appointment)
                                        @php($local = $appointment->starts_at->copy()->timezone('Asia/Karachi'))
                                        <div wire:key="appt-{{ $appointment->id }}" data-appointment="{{ $appointment->id }}" @class(['rounded-lg border px-2 py-1 text-xs',
                                            'border-primary/30 bg-primary-soft' => $appointment->status === \App\Enums\AppointmentStatus::Booked,
                                            'border-warning/30 bg-warning-soft' => in_array($appointment->status, [\App\Enums\AppointmentStatus::Waiting, \App\Enums\AppointmentStatus::InService], true),
                                            'border-success/30 bg-success-soft' => $appointment->status === \App\Enums\AppointmentStatus::Completed,
                                            'border-line bg-surface-sunken opacity-60' => in_array($appointment->status, [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow], true)])>
                                            <p class="font-semibold text-ink">{{ $local->format('g:i') }}–{{ $appointment->endsAt()->timezone('Asia/Karachi')->format('g:i A') }} · {{ $appointment->whoName() }}</p>
                                            <p class="text-ink-muted">{{ $appointment->servicesLabel() }} · {{ $appointment->status->label() }}</p>
                                            <x-appointments.actions :appointment="$appointment" :deposits-on="$depositsOn" :shop-name="$shopName" />
                                        </div>
                                    @endforeach
                                    @if ($column['id'] !== null && $slot->isEmpty())
                                        <button type="button" wire:click="openBooking(false, {{ $column['id'] }}, '{{ sprintf('%02d:00', $hour) }}')" class="h-full w-full rounded text-xs text-ink-faint opacity-0 hover:bg-surface-sunken hover:opacity-100">+ Book</button>
                                    @endif
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card title="Walk-in queue" :padding="false" data-walk-in-queue>
            @if ($queue->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-faint">Nobody waiting.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($queue as $appointment)
                        <li wire:key="queue-{{ $appointment->id }}" class="px-4 py-3 text-sm" data-queue-number="{{ $appointment->queue_number }}">
                            <p><span class="mr-2 rounded-full bg-primary-soft px-2 py-0.5 text-xs font-bold text-link">#{{ $appointment->queue_number }}</span><span class="font-semibold text-ink">{{ $appointment->whoName() }}</span></p>
                            <p class="mt-1 text-xs text-ink-muted">{{ $appointment->servicesLabel() }}{{ $appointment->employee ? ' · '.$appointment->employee->name : '' }} · {{ $appointment->status->label() }}</p>
                            <x-appointments.actions :appointment="$appointment" :deposits-on="false" :shop-name="$shopName" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.modal name="booking-form" max-width="lg">
        <form wire:submit="book" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">{{ $walkIn ? 'Walk-in' : 'New Booking' }} · {{ $day->format('d M') }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Customer" name="customer" for="bookCustomer" class="col-span-2">
                    <x-ui.select wire:model.live="customerId" id="bookCustomer">
                        <option value="">Not saved: type the name below</option>
                        <option value="__create__">+ New Customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @if ($customerId === '')
                    <x-ui.field label="Name" name="customerName" for="bookName"><x-ui.input wire:model="customerName" id="bookName" /></x-ui.field>
                    <x-ui.field label="Phone" name="phone" for="bookPhone"><x-ui.input wire:model="phone" id="bookPhone" /></x-ui.field>
                @endif
                <x-ui.field label="Staff" name="employee" for="bookStaff">
                    <x-ui.select wire:model="employeeId" id="bookStaff">
                        <option value="">Anyone</option>
                        @foreach ($allStaff as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @if (! $walkIn)
                    <x-ui.field label="Time" name="time" for="bookTime"><x-ui.input wire:model="time" id="bookTime" type="time" step="300" /></x-ui.field>
                @endif
            </div>
            <div class="space-y-2">
                <p class="text-sm font-medium text-ink">Services</p>
                @foreach ($services as $i => $row)
                    <div class="grid grid-cols-2 gap-2" wire:key="book-service-{{ $i }}">
                        <x-ui.select wire:model="services.{{ $i }}.service_item_id" aria-label="Service">
                            <option value="">Choose a service…</option>
                            @foreach ($menu as $service)
                                <option value="{{ $service->id }}">{{ $service->name }}{{ $service->duration_minutes ? ' · '.$service->duration_minutes.' min' : '' }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select wire:model="services.{{ $i }}.employee_id" aria-label="Done by">
                            <option value="">Same staff</option>
                            @foreach ($allStaff as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                @endforeach
                <button type="button" wire:click="addServiceRow" class="text-sm font-semibold text-link hover:underline">+ Another service</button>
                <x-ui.error :messages="$errors->get('services')" />
            </div>
            <x-ui.field label="Note" name="notes" for="bookNotes"><x-ui.input wire:model="notes" id="bookNotes" /></x-ui.field>
            <x-ui.error :messages="$errors->get('customer')" />
            <x-ui.error :messages="$errors->get('employee')" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'booking-form' })">Cancel</x-ui.button><x-ui.button type="submit">{{ $walkIn ? 'Add to queue' : 'Book' }}</x-ui.button></div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="move-form" max-width="sm">
        <form wire:submit="move" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">Move booking</h2>
            <x-ui.field label="Day" name="moveDate" for="moveDate"><x-ui.input wire:model="moveDate" id="moveDate" type="date" /></x-ui.field>
            <x-ui.field label="Time" name="moveTime" for="moveTime"><x-ui.input wire:model="moveTime" id="moveTime" type="time" step="300" /></x-ui.field>
            <x-ui.field label="Staff" name="moveEmployeeId" for="moveStaff">
                <x-ui.select wire:model="moveEmployeeId" id="moveStaff">
                    <option value="">Anyone</option>
                    @foreach ($allStaff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.error :messages="$errors->get('appointment')" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'move-form' })">Cancel</x-ui.button><x-ui.button type="submit">Move</x-ui.button></div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="deposit-form" max-width="sm">
        <form wire:submit="takeDeposit" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">Take a deposit</h2>
            <p class="text-sm text-ink-muted">Held for the customer and used on this booking's bill.</p>
            <x-ui.field label="Amount (Rs)" name="depositAmount" for="depositAmount"><x-ui.input wire:model="depositAmount" id="depositAmount" type="number" min="1" step="0.01" /></x-ui.field>
            <x-sales.payment-method-picker :accounts="$paymentAccounts" :value="$paymentMethod" amount="depositAmount" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'deposit-form' })">Cancel</x-ui.button><x-ui.button type="submit">Receive</x-ui.button></div>
        </form>
    </x-ui.modal>

    <livewire:customers.quick-create />
    <livewire:shop-accounts.quick-create />
</div>
