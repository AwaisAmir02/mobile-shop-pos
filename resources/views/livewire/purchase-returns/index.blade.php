<?php

use App\Actions\PurchaseReturns;
use App\Livewire\Concerns\Toasts;
use App\Models\PurchaseReturn;
use App\Support\BranchContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Goods sent back to vendors (W10), each with its debit note. */
new #[Layout('layouts.app')] #[Title('Purchase Returns')] class extends Component
{
    use Toasts;

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->usesTool('returns'), 404);
    }

    public function cancel(int $id): void
    {
        PurchaseReturns::cancel(PurchaseReturn::findOrFail($id), Auth::id());
        $this->toastSuccess('Return cancelled. The goods are back in stock and owed for again.');
    }

    public function debitNote(int $id): StreamedResponse
    {
        $return = PurchaseReturn::with(['items', 'vendor', 'user'])->findOrFail($id);
        $output = Pdf::loadView('pdf.debit-note', ['return' => $return, 'shop' => Auth::user()->shop, 'credit' => $return->credit()])->setPaper('a5')->output();

        return response()->streamDownload(fn () => print ($output), $return->number().'.pdf');
    }

    public function with(): array
    {
        return [
            'returns' => PurchaseReturn::query()->with(['vendor', 'items'])->latest('return_date')->latest('id')->limit(200)->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Expired, damaged or wrong stock sent back to the vendor, with a debit note for each">Purchase Returns</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="stock" />

    <div class="mb-4 flex justify-end">
        <x-ui.button href="{{ route('purchase-returns.create') }}" wire:navigate data-new-return>Return to Vendor</x-ui.button>
    </div>

    @if ($returns->isEmpty())
        <x-ui.empty-state title="No returns yet" description="When stock goes back to a vendor, record it here: it leaves stock and comes off what you owe them." />
    @else
        <x-ui.table stack :headers="['Debit note', 'Vendor', 'Date', 'Items', 'Value', '']">
            @foreach ($returns as $return)
                <x-ui.table-row wire:key="return-{{ $return->id }}" data-return="{{ $return->id }}" @class(['opacity-60' => $return->isCancelled()])>
                    <x-ui.table-cell primary>
                        <span class="font-semibold text-ink">{{ $return->number() }}</span>
                        <span class="block text-xs text-ink-faint">{{ $return->reasonLabel() }}{{ $return->isCancelled() ? ' · cancelled' : '' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Vendor">{{ $return->vendor?->name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Date">{{ $return->return_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Items" class="max-w-[16rem] truncate">{{ $return->items->map(fn ($i) => $i->product_name.' × '.\App\Support\Quantity::grouped($i->quantity))->implode(', ') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Value" class="font-semibold tabular-nums text-ink">Rs {{ number_format($return->total_value, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $return->number() }}">
                            <x-ui.menu-item wire:click="debitNote({{ $return->id }})">Debit note PDF</x-ui.menu-item>
                            @unless ($return->isCancelled())
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="cancel({{ $return->id }})" confirm="Cancel {{ $return->number() }}? The goods go back into stock and the vendor is owed for them again." confirm-label="Cancel return">Cancel</x-ui.menu-item>
                            @endunless
                        </x-ui.row-actions>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
