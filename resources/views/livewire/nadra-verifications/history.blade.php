<?php

use App\Models\NadraVerification;
use App\Services\NadraVerificationReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('NADRA Verification History')] class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return NadraVerification::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to));
    }

    public function with(): array
    {
        return [
            'verifications' => $this->filteredQuery()->with('customer')->latest()->paginate(15),
            'totalCollected' => $this->filteredQuery()->sum('total'),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to']);
    }

    public function downloadReceipt(int $verificationId, NadraVerificationReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(NadraVerification::findOrFail($verificationId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">NADRA Verification History</h1>
            <a href="{{ route('nadra-verifications.index') }}" wire:navigate>
                <x-ui.button size="sm">New Verification</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            @if ($from || $to)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.card :padding="false" class="w-full sm:w-auto">
            <div class="px-5 py-3 text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Collected</p>
                <p class="text-display-sm text-slate-900">Rs {{ number_format($totalCollected, 2) }}</p>
            </div>
        </x-ui.card>
    </div>

    @if ($verifications->isEmpty())
        <x-ui.empty-state
            title="No NADRA verifications yet"
            description="Completed verifications will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('nadra-verifications.index') }}" wire:navigate>
                    <x-ui.button>New Verification</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Phone', 'CNIC', 'Customer', 'Total', '']">
            @foreach ($verifications as $verification)
                <x-ui.table-row wire:key="nadra-verification-{{ $verification->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $verification->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->phone_number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->cnic_number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($verification->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $verification->id }})">
                            Download
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $verifications->links() }}
        </div>
    @endif
</div>
