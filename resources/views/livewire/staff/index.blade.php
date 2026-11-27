<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Staff (Services group): the people who do the work, so a bill line, a
 * booking or a job can say who. The same records as Payroll's employees, but
 * this screen never shows pay; a shop without Payroll can still list its
 * staff here. Who takes bookings decides the columns on the day calendar.
 */
new #[Layout('layouts.app')] #[Title('Staff')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;
    public string $name = '';
    public string $phone = '';
    public string $designation = '';
    public bool $takesAppointments = true;
    public bool $isActive = true;

    public function boot(): void
    {
        \App\Support\BranchContext::current()->shopWideForRequest();
    }

    public function open(?int $id = null): void
    {
        $this->resetErrorBag();
        $employee = $id ? Employee::findOrFail($id) : null;
        $this->editingId = $employee?->id;
        $this->name = $employee?->name ?? '';
        $this->phone = $employee?->phone ?? '';
        $this->designation = $employee?->designation ?? '';
        $this->takesAppointments = $employee?->takes_appointments ?? true;
        $this->isActive = $employee?->is_active ?? true;
        $this->dispatch('open-modal', name: 'staff-form');
    }

    public function save(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'designation' => ['nullable', 'string', 'max:100']]);
        $attributes = ['name' => trim($this->name), 'phone' => $this->phone ?: null, 'designation' => $this->designation ?: null, 'takes_appointments' => $this->takesAppointments, 'is_active' => $this->isActive];

        if ($this->editingId) {
            Employee::findOrFail($this->editingId)->update($attributes);
        } else {
            Employee::create($attributes + ['rate' => 0, 'joined_on' => now('Asia/Karachi')->toDateString()]);
        }

        $this->dispatch('close-modal', name: 'staff-form');
        $this->toastSuccess('Saved.');
    }

    public function with(): array
    {
        return ['staff' => Employee::query()->orderByDesc('is_active')->orderBy('name')->get()];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Who does the work: shown on bills, bookings and job cards, and paid commission">Staff</x-ui.page-header>
    </x-slot>

    <x-ui.card :padding="false">
        <x-slot name="actions">
            @if (auth()->user()->shop->usesTool(\App\Enums\ServiceTradeTool::StaffAttendance))
                <x-ui.button size="sm" variant="secondary" href="{{ route('staff-attendance.index') }}" wire:navigate>Attendance</x-ui.button>
            @endif
            <x-ui.button size="sm" variant="secondary" href="{{ route('staff-earnings.index') }}" wire:navigate>Commission</x-ui.button>
            <x-ui.button size="sm" wire:click="open" data-new-staff>Add Staff</x-ui.button>
        </x-slot>
        @if ($staff->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-faint">No staff yet.</p>
        @else
            <x-ui.table :headers="['Name', 'Role', 'Phone', 'Bookings', '']">
                @foreach ($staff as $person)
                    <tr wire:key="staff-{{ $person->id }}">
                        <td class="px-4 py-2.5 text-sm font-semibold text-ink">{{ $person->name }}@if (! $person->is_active) <x-ui.badge>Left</x-ui.badge>@endif</td>
                        <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $person->designation }}</td>
                        <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $person->phone }}</td>
                        <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $person->takes_appointments ? 'Takes bookings' : '—' }}</td>
                        <td class="px-4 py-2.5 text-right"><x-ui.button size="sm" variant="secondary" wire:click="open({{ $person->id }})">Edit</x-ui.button></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="staff-form" max-width="md">
        <form wire:submit="save" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">{{ $editingId ? 'Edit Staff' : 'Add Staff' }}</h2>
            <x-ui.field label="Name" name="name" for="staffName"><x-ui.input wire:model="name" id="staffName" /></x-ui.field>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Role" name="designation" for="staffRole"><x-ui.input wire:model="designation" id="staffRole" placeholder="Stylist, Trainer, Technician" /></x-ui.field>
                <x-ui.field label="Phone" name="phone" for="staffPhone"><x-ui.input wire:model="phone" id="staffPhone" /></x-ui.field>
            </div>
            <x-ui.checkbox wire:model="takesAppointments" label="Takes bookings (a column on the calendar)" />
            <x-ui.checkbox wire:model="isActive" label="Still working here" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'staff-form' })">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
        </form>
    </x-ui.modal>
</div>
