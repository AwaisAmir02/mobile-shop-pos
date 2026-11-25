<?php

use App\Actions\PurchaseReturns;
use App\Actions\VendorCredits;
use App\Livewire\Concerns\Toasts;
use App\Models\PurchaseReturn;
use App\Models\StockIn;
use App\Models\Vendor;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/** A return to one vendor: pick their batches still in stock and how many go back. */
new #[Layout('layouts.app')] #[Title('Return to Vendor')] class extends Component
{
    use Toasts;

    #[Url(as: 'vendor', except: '')]
    public string $vendorId = '';

    /** @var array<int, string> stock_in_id => quantity */
    public array $quantities = [];

    public string $reason = 'expired';
    public string $note = '';
    public string $returnDate = '';
    public string $search = '';

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->usesTool('returns'), 404);
        $this->returnDate = BatchExpiry::today();
    }

    public function updatedVendorId(): void
    {
        $this->quantities = [];
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->validate([
            'vendorId' => ['required', 'integer'],
            'returnDate' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['vendorId' => 'vendor', 'returnDate' => 'date']);

        $vendor = Vendor::findOrFail((int) $this->vendorId);
        $return = PurchaseReturns::create($vendor->id, $this->quantities, $this->reason, $this->note, $this->returnDate, Auth::id());
        $credit = $return->credit();

        $this->toastSuccess($return->number().' saved: Rs '.number_format((float) $return->total_value, 2).' off what you owe '.$vendor->name.($credit && (float) $credit->remaining > 0 ? '; Rs '.number_format((float) $credit->remaining, 2).' is credit with them.' : '.'));
        $this->redirectRoute('purchase-returns.index', navigate: true);
    }

    public function with(): array
    {
        $vendorId = $this->vendorId !== '' ? (int) $this->vendorId : null;
        $batches = $vendorId === null ? collect() : StockIn::query()
            ->where('vendor_id', $vendorId)
            ->where('quantity_remaining', '>', 0)
            ->when($this->search !== '', fn ($q) => $q->where('product_name', 'like', "%{$this->search}%"))
            ->orderBy('product_name')->orderBy('stock_date')
            ->limit(200)
            ->get();

        $value = 0.0;

        foreach ($this->quantities as $id => $quantity) {
            $batch = $batches->firstWhere('id', (int) $id);

            if ($batch && is_numeric($quantity) && (float) $quantity > 0 && $batch->cost_price !== null) {
                $value += \App\Support\Quantity::money($batch->cost_price, $quantity);
            }
        }

        return [
            'vendors' => Vendor::query()->orderBy('name')->get(),
            'batches' => $batches,
            'value' => round($value, 2),
            'owed' => $vendorId ? VendorCredits::owedTo($vendorId) : 0.0,
            'today' => BatchExpiry::today(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('purchase-returns.index') }}" back-label="Purchase Returns" subtitle="Each line comes out of the batch it was bought in, at that batch's cost">Return to Vendor</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card title="What is going back">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Vendor" name="vendorId" for="returnVendor">
                        <x-ui.select wire:model.live="vendorId" id="returnVendor">
                            <option value="">Choose the vendor…</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    @if ($vendorId !== '')
                        <x-ui.field label="Find product" for="returnSearch">
                            <x-ui.input wire:model.live.debounce.400ms="search" id="returnSearch" type="search" />
                        </x-ui.field>
                    @endif
                </div>

                @error('lines')<p class="mt-3 text-sm text-danger">{{ $message }}</p>@enderror

                @if ($vendorId !== '')
                    @if ($batches->isEmpty())
                        <p class="mt-5 text-sm text-ink-faint">Nothing bought from this vendor is in stock now.</p>
                    @else
                        <ul class="mt-5 divide-y divide-line" data-return-batches>
                            @foreach ($batches as $batch)
                                <li wire:key="batch-{{ $batch->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ $batch->product_name }}</p>
                                        <p class="text-xs text-ink-faint">
                                            Received {{ $batch->stock_date->format('d M Y') }}
                                            · {{ $batch->unit->amount($batch->quantity_remaining) }} left
                                            · {{ $batch->cost_price !== null ? 'Rs '.number_format($batch->cost_price, 2).' each' : 'no cost' }}
                                            @if ($batch->expiry_date)
                                                · expires {{ $batch->expiry_date->format('d M Y') }}{{ $batch->isExpired() ? ' (expired)' : '' }}
                                            @endif
                                            @if ($batch->batch_number)
                                                · batch {{ $batch->batch_number }}
                                            @endif
                                        </p>
                                        @error('lines.'.$batch->id)<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                                    </div>
                                    <input type="number" min="0" step="{{ $batch->unit->inputStep() }}" max="{{ (float) $batch->quantity_remaining }}" wire:model.live.debounce.400ms="quantities.{{ $batch->id }}" aria-label="Quantity of {{ $batch->product_name }} going back" placeholder="0" class="w-24 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm text-ink focus:border-link focus:ring-link" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Debit note">
            <div class="space-y-4">
                <x-ui.field label="Reason" name="reason" for="returnReason">
                    <x-ui.select wire:model="reason" id="returnReason">
                        @foreach (\App\Models\PurchaseReturn::REASONS as $reasonKey => $reasonLabel)
                            <option value="{{ $reasonKey }}">{{ $reasonLabel }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Date" name="returnDate" for="returnDate">
                    <x-ui.input wire:model="returnDate" id="returnDate" type="date" max="{{ $today }}" />
                </x-ui.field>
                <x-ui.field label="Note" name="note" for="returnNote" help="Optional; needed for Other">
                    <x-ui.input wire:model="note" id="returnNote" maxlength="255" />
                </x-ui.field>
                <div class="rounded-xl bg-surface-sunken px-3 py-2 text-sm">
                    <div class="flex justify-between"><span class="text-ink-faint">Value going back</span><span class="font-bold tabular-nums text-ink" data-return-value>Rs {{ number_format($value, 2) }}</span></div>
                    @if ($vendorId !== '')
                        <div class="mt-1 flex justify-between"><span class="text-ink-faint">You owe them now</span><span class="tabular-nums text-ink">Rs {{ number_format($owed, 2) }}</span></div>
                        <p class="mt-2 text-xs text-ink-faint">It comes off what you owe them{{ $value > $owed ? '; the Rs '.number_format($value - $owed, 2).' over that stays as credit with them' : '' }}.</p>
                    @endif
                </div>
                <x-ui.button type="button" wire:click="save" class="w-full justify-center" data-save-return>Save Return</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
