<?php

use App\Actions\BiometricDevices;
use App\Actions\CheckIns;
use App\Actions\StaffAttendance;
use App\Enums\CheckInSource;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\Toasts;
use App\Models\Employee;
use App\Services\CheckInCardPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff Attendance (Services group): who came in today and when, by
 * fingerprint, QR badge or a tap here, and days present in a month (which
 * a daily-wage payslip starts with).
 */
new #[Layout('layouts.app')] #[Title('Staff Attendance')] class extends Component
{
    use Toasts;

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $month = '';

    public string $scan = '';

    public function boot(): void
    {
        \App\Support\BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        $this->date = $this->date !== '' ? $this->date : now('Asia/Karachi')->toDateString();
        $this->month = $this->month !== '' ? $this->month : now('Asia/Karachi')->format('Y-m');
    }

    public function clock(int $employeeId): void
    {
        $employee = Employee::findOrFail($employeeId);
        StaffAttendance::punch($employee, CheckInSource::Desk, now(), null, Auth::id());
        $this->toastSuccess($employee->name.' clocked at '.now('Asia/Karachi')->format('g:i A').'.');
    }

    public function scanCode(): void
    {
        try {
            $result = CheckIns::scan(Auth::user()->shop_id, $this->scan, Auth::id());
            $this->toastSuccess($result['name'].($result['kind'] === 'staff' ? ' clocked.' : ' checked in.'));
        } catch (ValidationException $e) {
            $this->toastError(collect($e->errors())->flatten()->first());
        }

        $this->scan = '';
    }

    public function badge(int $employeeId, CheckInCardPdfService $pdf): StreamedResponse
    {
        return $pdf->download(Employee::findOrFail($employeeId));
    }

    public function sendToDevice(int $employeeId): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Biometric), 404);

        try {
            $employee = Employee::findOrFail($employeeId);
            $count = BiometricDevices::sendStaff($employee);
            $this->toastSuccess('Sent to '.$count.' '.str('device')->plural($count).'. Enrol the finger on the device under no. '.$employee->fresh()->attendance_pin.'.');
        } catch (ValidationException $e) {
            $this->toastError(collect($e->errors())->flatten()->first());
        }
    }

    public function with(): array
    {
        $first = \Carbon\Carbon::parse(($this->month ?: now()->format('Y-m')).'-01');
        $staff = Employee::query()->where('is_active', true)->orderBy('name')->get();

        return [
            'rows' => StaffAttendance::day(Auth::user()->shop_id, $this->date),
            'monthDays' => $staff->mapWithKeys(fn (Employee $e) => [$e->id => StaffAttendance::daysPresent($e, $first->toDateString(), $first->copy()->endOfMonth()->toDateString())]),
            'staff' => $staff,
            'biometricOn' => Auth::user()->shop->usesTool(ServiceTradeTool::Biometric),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('staff.index') }}" back-label="Staff" subtitle="Who came in and when: fingerprint, QR badge, or a tap here">Staff Attendance</x-ui.page-header>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-end gap-3">
        <x-ui.field label="Day" for="attDate" class="w-44"><x-ui.input wire:model.live="date" id="attDate" type="date" /></x-ui.field>
        <form wire:submit="scanCode" class="flex items-end gap-2">
            <x-ui.field label="Scan a badge" for="attScan" class="w-64"><x-ui.input wire:model="scan" id="attScan" placeholder="Point the scanner here" autocomplete="off" /></x-ui.field>
            <x-ui.button type="submit" variant="secondary">Clock</x-ui.button>
        </form>
    </div>

    <x-ui.card :padding="false" data-attendance-day>
        @if ($rows->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-faint">No staff yet. Add them under Staff.</p>
        @else
            <x-ui.table :headers="['Staff', 'In', 'Out', 'Hours', 'How', '']">
                @foreach ($rows as $row)
                    <tr wire:key="att-{{ $row['employee']->id }}">
                        <td class="px-4 py-2.5 text-sm font-semibold text-ink">{{ $row['employee']->name }}<span class="block text-xs font-normal text-ink-faint">{{ $monthDays[$row['employee']->id] ?? 0 }} days this month{{ $row['employee']->attendance_pin ? ' · no. '.$row['employee']->attendance_pin : '' }}</span></td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-ink">{{ $row['in']?->format('g:i A') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-ink">{{ $row['out']?->format('g:i A') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums text-ink-muted">{{ $row['minutes'] > 0 ? intdiv($row['minutes'], 60).'h '.($row['minutes'] % 60).'m' : '—' }}</td>
                        <td class="px-4 py-2.5 text-sm text-ink-muted">{{ implode(', ', $row['sources']) ?: '—' }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <div class="flex flex-wrap justify-end gap-1">
                                <x-ui.button size="sm" wire:click="clock({{ $row['employee']->id }})" data-clock="{{ $row['employee']->id }}">Clock in/out</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="badge({{ $row['employee']->id }})">Badge</x-ui.button>
                                @if ($biometricOn)
                                    <x-ui.button size="sm" variant="secondary" wire:click="sendToDevice({{ $row['employee']->id }})">To device</x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4 flex items-end gap-3">
        <x-ui.field label="Days present in" for="attMonth" class="w-48"><x-ui.input wire:model.live="month" id="attMonth" type="month" /></x-ui.field>
        <p class="pb-2 text-sm text-ink-faint">A daily-wage payslip for that month starts with these days.</p>
    </div>
</div>
