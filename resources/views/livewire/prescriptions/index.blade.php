<?php

use App\Enums\SaleStatus;
use App\Models\SalePrescription;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The prescription / controlled-drug register (App\Actions\Prescriptions):
 * every sale of a medicine the shop marked Prescription only or Controlled,
 * by serial number, for a period. Printable as it stands.
 */
new #[Layout('layouts.app')] #[Title('Prescription Register')] class extends Component
{
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** 'all', 'controlled', 'emergency' or 'missing' (details not entered yet). */
    #[Url(except: 'all')]
    public string $show = 'all';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: BatchExpiry::today();
    }

    public function with(): array
    {
        $entries = SalePrescription::query()
            ->with('sale')
            ->when($this->from, fn ($q) => $q->whereDate('dispensed_on', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('dispensed_on', '<=', $this->to))
            ->when($this->show === 'controlled', fn ($q) => $q->where('control', SalePrescription::CONTROLLED))
            ->when($this->show === 'emergency', fn ($q) => $q->where('is_emergency', true))
            ->when($this->show === 'missing', fn ($q) => $q->whereNull('prescriber_name')->where('is_emergency', false))
            ->orderBy('serial_number')
            ->get();

        $active = $entries->reject(fn ($entry) => $entry->sale?->status === SaleStatus::Cancelled);

        return [
            'entries' => $entries,
            'count' => $active->count(),
            'emergencies' => $active->where('is_emergency', true)->count(),
            'missing' => $active->filter->detailsMissing()->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('reports.index') }}" back-label="Reports" subtitle="Every sale of a Prescription only or Controlled medicine">Prescription Register</x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="$show !== 'all' ? 1 : 0">
        <x-ui.field label="From" name="from" for="from" class="sm:max-w-[12rem]">
            <x-ui.input wire:model.live="from" id="from" type="date" />
        </x-ui.field>
        <x-ui.field label="To" name="to" for="to" class="sm:max-w-[12rem]">
            <x-ui.input wire:model.live="to" id="to" type="date" />
        </x-ui.field>
        <x-ui.field label="Show" name="show">
            <x-ui.segmented name="rx-show" wire:model.live="show" :options="['all' => 'All', 'controlled' => 'Controlled', 'emergency' => 'Emergency', 'missing' => 'Details missing']" label="Show" />
        </x-ui.field>
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="Entries" :value="$count" sub="Cancelled sales not counted" />
        <x-ui.stat label="Emergency sales" :value="$emergencies" sub="Given without a prescriber" />
        <x-ui.stat label="Details missing" :value="$missing" sub="Sales not made at the register" />
    </x-ui.stat-row>

    <div class="mb-3 flex justify-end print:hidden">
        <x-ui.button variant="secondary" x-on:click="window.print()">Print</x-ui.button>
    </div>

    @if ($entries->isEmpty())
        <x-ui.empty-state title="No entries" description="Mark a medicine Prescription only or Controlled on its product page; each sale of it is written here." />
    @else
        <x-ui.table stack :headers="['No.', 'Date', 'Medicine', 'Qty', 'Patient', 'Prescriber', 'Sale']">
            @foreach ($entries as $entry)
                <x-ui.table-row wire:key="rx-{{ $entry->id }}" data-rx-row="{{ $entry->serial_number }}">
                    <x-ui.table-cell primary class="font-medium text-ink">{{ $entry->serial_number }}</x-ui.table-cell>
                    <x-ui.table-cell label="Date">{{ $entry->dispensed_on->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Medicine">
                        {{ $entry->product_name }}
                        <span class="block text-xs text-ink-faint">{{ SalePrescription::controlLabel($entry->control) }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Qty">{{ Quantity::normalize($entry->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Patient">{{ $entry->patient_name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Prescriber">
                        {{ $entry->detailsMissing() ? 'Not recorded yet' : ($entry->is_emergency ? 'Emergency: '.$entry->emergency_reason : $entry->prescriber_name.($entry->prescriber_reg_no ? ' ('.$entry->prescriber_reg_no.')' : '')) }}
                        {{ $entry->prescribed_on ? '· Rx '.$entry->prescribed_on->format('d M Y') : '' }}
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Sale">
                        @if ($entry->sale)
                            <a href="{{ route('sales.show', $entry->sale) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $entry->sale->invoiceNumber() }}</a>
                            {{ $entry->sale->status === SaleStatus::Cancelled ? '(cancelled)' : '' }}
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
