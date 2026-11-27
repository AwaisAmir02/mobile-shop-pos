@props([
    'lines' => [],
    'results',
    'plans',
    'staff',
    'packageChoices' => [],
    'search' => '',
    'billing' => null,
])

{{-- The register's services panel for a service business (SellsServices): pick from
     the menu, memberships and packages, or type a line (labour); each line says who
     did it, and a session can come out of the customer's package. Binds
     serviceLines.{key}.* and serviceSearch on the register. --}}
@php($input = 'rounded-lg border-line-strong bg-surface py-1.5 text-sm text-ink focus:border-link focus:ring-link')
<x-ui.card :padding="false" class="mb-6" data-services-panel>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
        <div>
            <p class="text-sm font-semibold text-ink">Services</p>
            <p class="text-xs text-ink-faint">{{ $billing ? 'Billing '.$billing.'. ' : '' }}Services and memberships go on the same bill as products.</p>
        </div>
        <button type="button" wire:click="addCustomService" class="text-sm font-semibold text-link hover:underline" data-add-custom-service>+ Type a line</button>
    </div>
    <div class="p-4 sm:p-5">
        <x-ui.input wire:model.live.debounce.250ms="serviceSearch" type="search" placeholder="Search services and memberships…" aria-label="Search services" autocomplete="off" />
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($results as $service)
                <button type="button" wire:key="svc-{{ $service->id }}" wire:click="addService({{ $service->id }})" data-service-pick="{{ $service->id }}" class="rounded-full border border-line px-3 py-1.5 text-sm text-ink hover:border-link hover:text-link">
                    {{ $service->name }} · Rs {{ number_format((float) $service->price, 0) }}@if ($service->duration_minutes) · {{ $service->duration_minutes }} min @endif
                </button>
            @endforeach
            @foreach ($plans as $plan)
                <button type="button" wire:key="plan-{{ $plan->id }}" wire:click="addMembershipPlan({{ $plan->id }})" data-plan-pick="{{ $plan->id }}" class="rounded-full border border-primary/30 bg-primary-soft px-3 py-1.5 text-sm text-link hover:border-link">
                    {{ $plan->name }} · Rs {{ number_format((float) $plan->price, 0) }}
                </button>
            @endforeach
            @if ($results->isEmpty() && $plans->isEmpty())
                <p class="text-sm text-ink-faint">{{ $search !== '' ? 'Nothing on the menu matches "'.$search.'".' : 'No priced services yet: add them under Service Menu.' }}</p>
            @endif
        </div>
    </div>

    @if ($lines !== [])
        <ul class="divide-y divide-line border-t border-line">
            @foreach ($lines as $key => $line)
                @php($packages = $packageChoices[$key] ?? collect())
                <li wire:key="service-line-{{ $key }}" data-service-line="{{ $line['kind'] }}" class="space-y-2 px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-surface-sunken px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ \App\Enums\SaleLineKind::from($line['kind'])->label() }}</span>
                        @if ($line['service_item_id'] === null && $line['membership_plan_id'] === null)
                            <input type="text" wire:model.blur="serviceLines.{{ $key }}.name" placeholder="What was done (e.g. Gas refill)" class="{{ $input }} min-w-0 flex-1" aria-label="Description" />
                        @else
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{{ $line['name'] }}</span>
                        @endif
                        <button type="button" wire:click="removeServiceLine('{{ $key }}')" class="text-xs font-semibold text-danger hover:underline">Remove</button>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        @if ($line['kind'] !== 'package')
                            <label class="text-xs text-ink-faint">Price (Rs)
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="serviceLines.{{ $key }}.unit_price" class="{{ $input }} block w-28 text-right" />
                            </label>
                            @if ($line['kind'] === 'service')
                                <label class="text-xs text-ink-faint">Qty
                                    <input type="number" min="1" step="1" wire:model.live.debounce.400ms="serviceLines.{{ $key }}.quantity" class="{{ $input }} block w-16 text-right" />
                                </label>
                            @endif
                            <label class="text-xs text-ink-faint">Discount (Rs)
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="serviceLines.{{ $key }}.discount" class="{{ $input }} block w-24 text-right" />
                            </label>
                        @endif
                        <label class="text-xs text-ink-faint">{{ $line['kind'] === 'membership' ? 'Sold by' : 'Done by' }}
                            <select wire:model.live="serviceLines.{{ $key }}.performed_by" class="{{ $input }} block w-40" data-performer>
                                <option value="">—</option>
                                @foreach ($staff as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if ($line['kind'] === 'package')
                            <span class="text-sm text-success">From the package · no charge</span>
                            <button type="button" wire:click="usePackage('{{ $key }}', null)" class="text-xs font-semibold text-link hover:underline">Charge instead</button>
                        @elseif ($line['kind'] === 'service' && $packages->isNotEmpty())
                            @foreach ($packages as $package)
                                <button type="button" wire:click="usePackage('{{ $key }}', {{ $package->id }})" class="rounded-full border border-success/40 px-3 py-1 text-xs font-semibold text-success hover:bg-success/10" data-use-package="{{ $package->id }}">
                                    Use {{ $package->plan_name }} ({{ $package->sessionsLeft() }} left)
                                </button>
                            @endforeach
                        @endif
                        <span class="ml-auto text-sm font-bold tabular-nums text-ink">Rs {{ number_format($line['kind'] === 'package' ? 0 : max(0, (is_numeric($line['unit_price']) ? (float) $line['unit_price'] : 0) * max(1, (int) $line['quantity']) - (is_numeric($line['discount']) ? (float) $line['discount'] : 0)), 2) }}</span>
                    </div>
                    <x-ui.error :messages="$errors->get('serviceLines.'.$key.'.name')" />
                    <x-ui.error :messages="$errors->get('serviceLines.'.$key.'.unit_price')" />
                    <x-ui.error :messages="$errors->get('serviceLines.'.$key.'.discount')" />
                </li>
            @endforeach
        </ul>
    @endif
    <div class="px-4 pb-3 sm:px-5"><x-ui.error :messages="$errors->get('serviceLines')" /></div>
</x-ui.card>
