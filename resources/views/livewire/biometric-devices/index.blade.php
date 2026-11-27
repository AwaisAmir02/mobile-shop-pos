<?php

use App\Actions\BiometricDevices;
use App\Livewire\Concerns\Toasts;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Fingerprint Devices (gyms): ZKTeco-protocol devices (ADMS / "Cloud server")
 * that push check-ins to us. The shop types in the device's serial number
 * and sets the device's server address to ours (shown here).
 */
new #[Layout('layouts.app')] #[Title('Fingerprint Devices')] class extends Component
{
    use Toasts;

    public string $name = '';
    public string $serialNumber = '';
    public bool $removeExpired = false;

    public function register(): void
    {
        BiometricDevices::register(Auth::user()->shop_id, ['name' => $this->name, 'serial_number' => $this->serialNumber, 'remove_expired' => $this->removeExpired]);
        $this->reset(['name', 'serialNumber', 'removeExpired']);
        $this->toastSuccess('Device added. Set its Cloud Server address as shown below.');
    }

    public function toggle(int $id, string $field): void
    {
        abort_unless(in_array($field, ['is_active', 'remove_expired'], true), 404);
        $device = BiometricDevice::findOrFail($id);
        $device->update([$field => ! $device->{$field}]);
    }

    public function syncNow(int $id): void
    {
        $result = BiometricDevices::syncStanding(BiometricDevice::findOrFail($id));
        $this->toastSuccess('Queued: '.$result['removed'].' to remove, '.$result['restored'].' to put back. The device collects them within a minute.');
    }

    public function with(): array
    {
        return [
            'devices' => BiometricDevice::query()->orderBy('name')->get(),
            'commands' => BiometricCommand::query()->latest('id')->limit(15)->get(),
            'host' => parse_url(config('app.url'), PHP_URL_HOST),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('check-ins.index') }}" back-label="Check-ins" subtitle="Fingerprint machines that send check-ins here">Fingerprint Devices</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Add a device" data-add-device>
            <form wire:submit="register" class="space-y-3">
                <x-ui.field label="Name" name="name" for="deviceName"><x-ui.input wire:model="name" id="deviceName" placeholder="Front door" /></x-ui.field>
                <x-ui.field label="Serial number" name="serial_number" for="deviceSerial" help="On the device: Menu › System Info › Device Info (SN)"><x-ui.input wire:model="serialNumber" id="deviceSerial" class="uppercase" /></x-ui.field>
                <x-ui.checkbox wire:model="removeExpired" label="Take members off the device when their membership ends" />
                <x-ui.button type="submit">Add</x-ui.button>
            </form>
            <div class="mt-5 rounded-xl bg-surface-sunken p-3 text-sm text-ink-muted" data-device-setup>
                <p class="font-semibold text-ink">On the device</p>
                <p class="mt-1">Menu › Comm › Cloud Server Setting (ADMS):</p>
                <p>Server address <strong class="text-ink">{{ $host }}</strong>, port <strong class="text-ink">80</strong>, HTTPS off unless your device supports it.</p>
                <p class="mt-1">Keep the device's clock on Pakistan time. Enrol each member's finger under their member number (Members › To device).</p>
            </div>
        </x-ui.card>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Devices" :padding="false" data-devices>
                @if ($devices->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-ink-faint">No devices yet.</p>
                @else
                    <x-ui.table :headers="['Device', 'Last heard', 'Expired members', '']">
                        @foreach ($devices as $device)
                            <tr wire:key="device-{{ $device->id }}">
                                <td class="px-4 py-2.5 text-sm"><span class="font-semibold text-ink">{{ $device->name }}</span> <span class="block text-xs text-ink-faint">SN {{ $device->serial_number }}{{ $device->firmware ? ' · '.$device->firmware : '' }}</span></td>
                                <td class="px-4 py-2.5 text-sm"><x-ui.badge :variant="$device->isOnline() ? 'success' : 'neutral'">{{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Never' }}</x-ui.badge>@if (! $device->is_active) <x-ui.badge>Off</x-ui.badge>@endif</td>
                                <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $device->remove_expired ? 'Taken off' : 'Left on' }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        @if ($device->remove_expired)
                                            <x-ui.button size="sm" variant="secondary" wire:click="syncNow({{ $device->id }})">Sync now</x-ui.button>
                                        @endif
                                        <x-ui.button size="sm" variant="secondary" wire:click="toggle({{ $device->id }}, 'remove_expired')">{{ $device->remove_expired ? 'Leave expired on' : 'Take expired off' }}</x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" wire:click="toggle({{ $device->id }}, 'is_active')">{{ $device->is_active ? 'Turn off' : 'Turn on' }}</x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            @if ($commands->isNotEmpty())
                <x-ui.card title="Recent commands" :padding="false">
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($commands as $command)
                            <li class="flex justify-between gap-3 px-5 py-2"><span class="truncate text-ink-muted">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before($command->command, "\t"), 60) }}</span><span class="shrink-0 text-xs text-ink-faint">{{ $command->status->label() }}</span></li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
