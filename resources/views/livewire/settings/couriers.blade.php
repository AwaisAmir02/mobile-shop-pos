<?php

use App\Couriers\CourierException;
use App\Enums\ConnectionStatus;
use App\Enums\CourierKey;
use App\Livewire\Concerns\Toasts;
use App\Models\CourierConnection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Settings › Integrations › Couriers: the shop connects its OWN account with
 * each courier. Credentials are checked against the courier before they're
 * saved, and kept encrypted. Secret fields are never sent back to the page.
 */
new class extends Component
{
    use Toasts;

    public ?string $editing = null;

    /** @var array<string, string> */
    public array $fields = [];

    /** @var array<string, string> courier => chosen pickup address code */
    public array $pickup = [];

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && Auth::user()->shop?->couriers_enabled, 403);
    }

    public function mount(): void
    {
        $this->pickup = CourierConnection::all()->mapWithKeys(fn ($c) => [$c->courier->value => (string) $c->setting('pickup_address_code', '')])->all();
    }

    public function updatedPickup(string $code, string $courier): void
    {
        $this->setPickup($courier, $code);
    }

    protected function connectionFor(CourierKey $courier): ?CourierConnection
    {
        return CourierConnection::where('courier', $courier->value)->first();
    }

    public function edit(string $courier): void
    {
        $key = CourierKey::from($courier);
        abort_unless($key->isAvailable(), 404);

        $connection = $this->connectionFor($key);
        $this->editing = $key->value;
        $this->fields = collect($key->provider()->credentialFields())
            // Secrets stay blank: leaving one blank keeps what's saved.
            ->mapWithKeys(fn ($field) => [$field['key'] => $field['secret'] ? '' : (string) ($connection?->credential($field['key']) ?? array_key_first($field['options'] ?? []) ?? '')])
            ->all();
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editing', 'fields']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $key = CourierKey::from((string) $this->editing);
        $provider = $key->provider();
        $existing = $this->connectionFor($key);

        $credentials = [];
        foreach ($provider->credentialFields() as $field) {
            $value = trim((string) ($this->fields[$field['key']] ?? ''));

            if ($value === '' && $field['secret'] && $existing?->credential($field['key'])) {
                $value = (string) $existing->credential($field['key']);
            }

            if ($value === '') {
                $this->addError('fields.'.$field['key'], $field['label'].' is required.');

                return;
            }

            if (isset($field['options']) && ! array_key_exists($value, $field['options'])) {
                $this->addError('fields.'.$field['key'], 'Choose one of the options.');

                return;
            }

            $credentials[$field['key']] = mb_substr($value, 0, 500);
        }

        $connection = $existing ?? new CourierConnection(['courier' => $key]);
        $connection->credentials = $credentials;

        try {
            $settings = $provider->verify($connection);
        } catch (CourierException $e) {
            $this->addError('connect', $e->getMessage());

            return;
        }

        $kept = $connection->settings ?? [];
        $settings = $settings + $kept;

        // PostEx-style pickup addresses: keep the chosen one if it still exists, else take the first.
        if (isset($settings['pickup_addresses'])) {
            $codes = array_column($settings['pickup_addresses'], 'code');
            $settings['pickup_address_code'] = in_array($kept['pickup_address_code'] ?? null, $codes, true) ? $kept['pickup_address_code'] : ($codes[0] ?? null);
        }

        $connection->fill([
            'settings' => $settings,
            'status' => ConnectionStatus::Connected,
            'last_error' => null,
            'verified_at' => now(),
            'connected_by' => Auth::id(),
        ])->save();

        $this->pickup[$key->value] = (string) ($settings['pickup_address_code'] ?? '');
        $this->cancelEdit();
        $this->toastSuccess($key->label().' connected.');
    }

    public function setPickup(string $courier, string $code): void
    {
        $connection = $this->connectionFor(CourierKey::from($courier)) ?? abort(404);
        $codes = array_column($connection->setting('pickup_addresses', []), 'code');
        abort_unless(in_array($code, $codes, true), 422);

        $connection->update(['settings' => ['pickup_address_code' => $code] + ($connection->settings ?? [])]);
        $this->toastSuccess('Pickup address saved.');
    }

    public function disconnect(string $courier): void
    {
        $connection = $this->connectionFor(CourierKey::from($courier)) ?? abort(404);

        // Kept (not deleted) so parcels already booked still show; nothing new can be booked.
        $connection->update(['status' => ConnectionStatus::Revoked, 'credentials' => [], 'last_error' => null]);
        $this->toastSuccess(CourierKey::from($courier)->label().' disconnected.');
    }

    public function with(): array
    {
        $connections = CourierConnection::all()->keyBy(fn ($c) => $c->courier->value);

        return [
            'couriers' => CourierKey::connectable(),
            'connections' => $connections,
        ];
    }
}; ?>

<div class="space-y-4">
    <x-ui.card title="Couriers" description="Book parcels with your own courier accounts. You sign up with the courier yourself, and the courier pays your cash-on-delivery money to you directly, as it does today. We only send them your bookings.">
        <div class="divide-y divide-line">
            @foreach ($couriers as $courier)
                @php($connection = $connections->get($courier->value))
                <div wire:key="courier-{{ $courier->value }}" class="py-4 first:pt-0 last:pb-0" data-courier="{{ $courier->value }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $courier->label() }}</p>
                            @if (! $courier->isAvailable())
                                <p class="text-sm text-ink-faint">{{ $courier->unavailableReason() }}</p>
                            @elseif ($connection?->isActive())
                                <p class="text-sm text-ink-faint">Checked {{ $connection->verified_at?->diffForHumans() }}{{ $connection->credential('environment') === 'staging' ? ' · test account' : '' }}</p>
                            @else
                                <p class="text-sm text-ink-faint">Not connected</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if (! $courier->isAvailable())
                                <x-ui.badge variant="neutral">Coming later</x-ui.badge>
                            @elseif ($connection?->isActive())
                                <x-ui.badge variant="success">Connected</x-ui.badge>
                                @if ($editing !== $courier->value)
                                    <x-ui.button size="sm" variant="secondary" wire:click="edit('{{ $courier->value }}')">Change</x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" wire:click="disconnect('{{ $courier->value }}')" confirm="Disconnect {{ $courier->label() }}? Parcels already booked stay listed, but you can't book new ones or refresh their status until you connect again.">Disconnect</x-ui.button>
                                @endif
                            @elseif ($editing !== $courier->value)
                                <x-ui.button size="sm" wire:click="edit('{{ $courier->value }}')">Connect</x-ui.button>
                            @endif
                        </div>
                    </div>

                    @if ($connection?->isActive() && $connection->setting('pickup_addresses'))
                        <div class="mt-3 max-w-xl">
                            <x-ui.field label="Pickup address" name="pickup" for="pickup-{{ $courier->value }}" help="Where {{ $courier->label() }} collects your parcels. Add more in your {{ $courier->label() }} dashboard, then press Change to reload them.">
                                <div wire:key="pickup-{{ $courier->value }}-{{ $connection->setting('pickup_address_code') }}">
                                    <x-ui.select wire:model.live="pickup.{{ $courier->value }}" id="pickup-{{ $courier->value }}">
                                        @foreach ($connection->setting('pickup_addresses') as $address)
                                            <option value="{{ $address['code'] }}">{{ $address['label'] ?: $address['code'] }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                            </x-ui.field>
                        </div>
                    @endif

                    @if ($editing === $courier->value)
                        @php($provider = $courier->provider())
                        <form wire:submit="save" class="mt-4 space-y-4 rounded-xl border border-line bg-surface-sunken/50 p-4" data-courier-form>
                            <p class="text-sm text-ink-muted">{{ $provider->credentialHelp() }}</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                @foreach ($provider->credentialFields() as $field)
                                    <x-ui.field :label="$field['label']" name="fields.{{ $field['key'] }}" for="courier-{{ $field['key'] }}" :help="$field['secret'] && $connection?->credential($field['key']) ? $field['help'].' Leave blank to keep the saved one.' : $field['help']">
                                        @if (isset($field['options']))
                                            <x-ui.select wire:model="fields.{{ $field['key'] }}" id="courier-{{ $field['key'] }}">
                                                @foreach ($field['options'] as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        @elseif ($field['secret'])
                                            <x-ui.password-input wire:model="fields.{{ $field['key'] }}" id="courier-{{ $field['key'] }}" autocomplete="off" />
                                        @else
                                            <x-ui.input wire:model="fields.{{ $field['key'] }}" id="courier-{{ $field['key'] }}" autocomplete="off" />
                                        @endif
                                    </x-ui.field>
                                @endforeach
                            </div>
                            <x-ui.error :messages="$errors->get('connect')" />
                            <div class="flex gap-2">
                                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                                    <span wire:loading.remove wire:target="save">Check &amp; connect</span>
                                    <span wire:loading wire:target="save">Checking with {{ $courier->label() }}…</span>
                                </x-ui.button>
                                <x-ui.button type="button" variant="secondary" wire:click="cancelEdit">Cancel</x-ui.button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
