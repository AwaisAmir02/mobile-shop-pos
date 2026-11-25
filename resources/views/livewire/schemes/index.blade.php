<?php

use App\Actions\Schemes;
use App\Livewire\Concerns\ChoosesPaymentSource;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use App\Models\Scheme;
use App\Models\SchemeClaim;
use App\Models\Vendor;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trade schemes (W9) and claiming their free goods back from the companies
 * that fund them.
 */
new #[Layout('layouts.app')] #[Title('Schemes')] class extends Component
{
    use ChoosesPaymentSource, Toasts;

    #[Url(except: 'schemes')]
    public string $tab = 'schemes';

    public ?int $editingId = null;
    public string $name = '';
    public string $productId = '';
    public string $vendorId = '';
    public string $buyQuantity = '';
    public string $freeQuantity = '';
    public string $startsOn = '';
    public string $endsOn = '';
    public bool $isActive = true;

    // Claims
    public string $claimVendorId = '';
    public string $claimFrom = '';
    public string $claimTo = '';
    public string $claimNote = '';
    public ?int $settlingId = null;
    public string $settleAmount = '';
    public string $settlement = 'credit';

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->usesTool('schemes'), 404);
        $this->tab = in_array($this->tab, ['schemes', 'claims'], true) ? $this->tab : 'schemes';
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'name', 'productId', 'vendorId', 'buyQuantity', 'freeQuantity', 'endsOn']);
        $this->isActive = true;
        $this->startsOn = BatchExpiry::today();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'scheme-form');
    }

    public function openEdit(int $id): void
    {
        $scheme = Scheme::findOrFail($id);
        $this->editingId = $scheme->id;
        $this->name = $scheme->name;
        $this->productId = (string) $scheme->product_id;
        $this->vendorId = (string) ($scheme->vendor_id ?? '');
        $this->buyQuantity = (string) $scheme->buy_quantity;
        $this->freeQuantity = (string) $scheme->free_quantity;
        $this->startsOn = $scheme->starts_on->toDateString();
        $this->endsOn = $scheme->ends_on?->toDateString() ?? '';
        $this->isActive = $scheme->is_active;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'scheme-form');
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'productId' => ['required', 'integer', \Illuminate\Validation\Rule::exists('products', 'id')->where('shop_id', Auth::user()->shop_id)],
            'vendorId' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('vendors', 'id')->where('shop_id', Auth::user()->shop_id)],
            'buyQuantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'freeQuantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
        ], [], ['productId' => 'product', 'vendorId' => 'company', 'buyQuantity' => 'buy quantity', 'freeQuantity' => 'free quantity', 'startsOn' => 'start date', 'endsOn' => 'end date']);

        $attributes = [
            'name' => $this->name,
            'product_id' => (int) $this->productId,
            'vendor_id' => $this->vendorId !== '' ? (int) $this->vendorId : null,
            'buy_quantity' => (int) $this->buyQuantity,
            'free_quantity' => (int) $this->freeQuantity,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn !== '' ? $this->endsOn : null,
            'is_active' => $this->isActive,
        ];

        $scheme = $this->editingId ? Scheme::findOrFail($this->editingId) : null;

        // Once used, what it gives and who funds it are history: only the name, dates and pause can change.
        if ($scheme && $scheme->redemptions()->exists()) {
            $attributes = array_intersect_key($attributes, array_flip(['name', 'starts_on', 'ends_on', 'is_active']));
        }

        $scheme ? $scheme->update($attributes) : Scheme::create($attributes);

        $this->dispatch('close-modal', name: 'scheme-form');
        $this->toastSuccess('Scheme saved.');
    }

    public function createClaim(): void
    {
        $this->validate([
            'claimVendorId' => ['required', 'integer'],
            'claimFrom' => ['nullable', 'date'],
            'claimTo' => ['nullable', 'date'],
            'claimNote' => ['nullable', 'string', 'max:255'],
        ], [], ['claimVendorId' => 'company']);

        $claim = Schemes::claim((int) $this->claimVendorId, $this->claimFrom ?: null, $this->claimTo ?: null, $this->claimNote, Auth::id());
        $this->reset(['claimVendorId', 'claimFrom', 'claimTo', 'claimNote']);
        $this->toastSuccess($claim->number().' for Rs '.number_format((float) $claim->claimed_amount, 2).' created. Download the statement to send it.');
    }

    public function openSettle(int $id): void
    {
        $claim = SchemeClaim::findOrFail($id);
        $this->settlingId = $claim->id;
        $this->settleAmount = (string) $claim->claimed_amount;
        $this->settlement = 'credit';
        $this->paidFrom = 'cash';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'settle-claim');
    }

    public function settle(): void
    {
        $this->validate(['settleAmount' => ['required', 'numeric', 'min:0'], 'settlement' => ['required', 'in:credit,cash']], [], ['settleAmount' => 'amount']);
        [$method, $accountId] = $this->paidFromParts();
        Schemes::settle(SchemeClaim::findOrFail($this->settlingId), (float) $this->settleAmount, $this->settlement, $method, $accountId, Auth::id());

        $this->dispatch('close-modal', name: 'settle-claim');
        $this->toastSuccess('Claim settled.');
    }

    public function cancelClaim(int $id): void
    {
        Schemes::cancelClaim(SchemeClaim::findOrFail($id));
        $this->toastSuccess('Claim withdrawn. Its free goods can be claimed again.');
    }

    public function statement(int $id): StreamedResponse
    {
        $claim = SchemeClaim::with(['vendor', 'redemptions.scheme', 'redemptions.sale.customer'])->findOrFail($id);
        $output = Pdf::loadView('pdf.scheme-claim', ['claim' => $claim, 'shop' => Auth::user()->shop])->setPaper('a4')->output();

        return response()->streamDownload(fn () => print ($output), $claim->number().'.pdf');
    }

    public function with(): array
    {
        $today = BatchExpiry::today();
        $summary = Schemes::summary();
        $vendors = Vendor::query()->orderBy('name')->get();

        return [
            'schemes' => Scheme::query()->with(['product', 'vendor'])->withCount('redemptions')->latest('id')->get(),
            'claims' => SchemeClaim::query()->with('vendor')->latest('id')->limit(100)->get(),
            'summary' => $summary->map(fn ($row) => $row + ['vendor' => $vendors->firstWhere('id', $row['vendor_id'])]),
            'products' => Product::query()->whereNull('archived_at')->orderBy('name')->limit(1000)->get(['id', 'name']),
            'vendors' => $vendors,
            'today' => $today,
            'settling' => $this->settlingId ? SchemeClaim::find($this->settlingId) : null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Buy so many, get some free. The free goods given are claimed back from the company that pays for the scheme.">Schemes</x-ui.page-header>
    </x-slot>

    <div class="mb-5">
        <x-ui.segmented name="scheme-tab" wire:model.live="tab" :options="['schemes' => 'Schemes', 'claims' => 'Claims']" label="Show" />
    </div>

    @if ($tab === 'schemes')
        <div class="mb-4 flex justify-end">
            <x-ui.button wire:click="openCreate" data-new-scheme>New Scheme</x-ui.button>
        </div>

        @if ($schemes->isEmpty())
            <x-ui.empty-state title="No schemes yet" description="Add the company's trade offers, like 10 + 1 free. The register then adds the free pieces by itself." />
        @else
            <x-ui.table stack :headers="['Scheme', 'Product', 'Offer', 'Runs', 'Paid by', 'Used', '']">
                @foreach ($schemes as $scheme)
                    <x-ui.table-row wire:key="scheme-{{ $scheme->id }}" data-scheme="{{ $scheme->id }}">
                        <x-ui.table-cell primary class="font-semibold text-ink">{{ $scheme->name }}</x-ui.table-cell>
                        <x-ui.table-cell label="Product">{{ $scheme->product?->name }}</x-ui.table-cell>
                        <x-ui.table-cell label="Offer">{{ $scheme->offerLabel() }}</x-ui.table-cell>
                        <x-ui.table-cell label="Runs">
                            {{ $scheme->starts_on->format('d M Y') }} – {{ $scheme->ends_on?->format('d M Y') ?? 'no end' }}
                            <span class="block text-xs {{ $scheme->runsOn($today) ? 'text-success' : 'text-ink-faint' }}">{{ $scheme->runsOn($today) ? 'Running' : ($scheme->is_active ? 'Not running today' : 'Paused') }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Paid by">{{ $scheme->vendor?->name ?? 'The shop' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Used">{{ $scheme->redemptions_count }} {{ \Illuminate\Support\Str::plural('sale', $scheme->redemptions_count) }}</x-ui.table-cell>
                        <x-ui.table-cell actions align="right">
                            <x-ui.button size="sm" variant="secondary" wire:click="openEdit({{ $scheme->id }})">Edit</x-ui.button>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    @else
        <x-ui.card title="To claim, by company" description="Free goods given under schemes a company pays for, at the cost they left stock at." class="mb-6" data-claim-summary>
            @if ($summary->isEmpty())
                <p class="text-sm text-ink-faint">No company-paid free goods given yet.</p>
            @else
                <x-ui.table :headers="['Company', 'Not claimed', 'Claimed, waiting', 'Received', 'Short paid']">
                    @foreach ($summary as $row)
                        <x-ui.table-row wire:key="summary-{{ $row['vendor_id'] }}">
                            <x-ui.table-cell primary class="font-semibold text-ink">{{ $row['vendor']?->name }}</x-ui.table-cell>
                            <x-ui.table-cell label="Not claimed" class="tabular-nums">Rs {{ number_format($row['unclaimed'], 2) }}</x-ui.table-cell>
                            <x-ui.table-cell label="Claimed, waiting" class="tabular-nums">Rs {{ number_format($row['waiting'], 2) }}</x-ui.table-cell>
                            <x-ui.table-cell label="Received" class="tabular-nums">Rs {{ number_format($row['settled'], 2) }}</x-ui.table-cell>
                            <x-ui.table-cell label="Short paid" class="tabular-nums">Rs {{ number_format($row['short'], 2) }}</x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif

            <div class="mt-5 grid grid-cols-1 gap-3 border-t border-line pt-5 sm:grid-cols-5">
                <x-ui.field label="Company" name="claimVendorId" for="claimVendor" class="sm:col-span-2">
                    <x-ui.select wire:model="claimVendorId" id="claimVendor">
                        <option value="">Choose…</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Sales from" name="claimFrom" for="claimFrom">
                    <x-ui.input wire:model="claimFrom" id="claimFrom" type="date" />
                </x-ui.field>
                <x-ui.field label="Sales to" name="claimTo" for="claimTo">
                    <x-ui.input wire:model="claimTo" id="claimTo" type="date" />
                </x-ui.field>
                <div class="flex items-end">
                    <x-ui.button wire:click="createClaim" class="w-full justify-center" data-create-claim>Create Claim</x-ui.button>
                </div>
            </div>
            @error('claim')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
        </x-ui.card>

        @if ($claims->isNotEmpty())
            <x-ui.table stack :headers="['Claim', 'Company', 'Claimed', 'Status', '']">
                @foreach ($claims as $claim)
                    <x-ui.table-row wire:key="claim-{{ $claim->id }}" data-claim="{{ $claim->id }}">
                        <x-ui.table-cell primary>
                            <span class="font-semibold text-ink">{{ $claim->number() }}</span>
                            <span class="block text-xs text-ink-faint">{{ $claim->claimed_on->format('d M Y') }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Company">{{ $claim->vendor?->name }}</x-ui.table-cell>
                        <x-ui.table-cell label="Claimed" class="tabular-nums">Rs {{ number_format($claim->claimed_amount, 2) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Status">
                            {{ ucfirst($claim->status) }}
                            @if ($claim->status === 'settled')
                                <span class="block text-xs text-ink-faint">Rs {{ number_format($claim->settled_amount, 2) }} {{ $claim->settlement === 'cash' ? 'paid' : 'as credit' }} on {{ $claim->settled_on->format('d M Y') }}</span>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell actions align="right">
                            <x-ui.row-actions label="Actions for {{ $claim->number() }}">
                                <x-ui.menu-item wire:click="statement({{ $claim->id }})">Claim statement PDF</x-ui.menu-item>
                                @if ($claim->status === 'submitted')
                                    <x-ui.menu-item wire:click="openSettle({{ $claim->id }})">Settled by company</x-ui.menu-item>
                                    <x-ui.menu-divider />
                                    <x-ui.menu-item danger wire:click="cancelClaim({{ $claim->id }})" confirm="Withdraw {{ $claim->number() }}? Its free goods can be claimed again." confirm-label="Withdraw">Withdraw</x-ui.menu-item>
                                @endif
                            </x-ui.row-actions>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    @endif

    <x-ui.modal name="scheme-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-bold text-ink">{{ $editingId ? 'Edit Scheme' : 'New Scheme' }}</h2>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Name" name="name" for="schemeName" class="sm:col-span-2">
                    <x-ui.input wire:model="name" id="schemeName" maxlength="120" placeholder="e.g. Tapal November offer" />
                </x-ui.field>
                <x-ui.field label="Product" name="productId" for="schemeProduct" class="sm:col-span-2">
                    <x-ui.select wire:model="productId" id="schemeProduct">
                        <option value="">Choose…</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Buy (pieces)" name="buyQuantity" for="schemeBuy" help="e.g. 120 for 10 cartons of 12">
                    <x-ui.input wire:model="buyQuantity" id="schemeBuy" type="number" min="1" step="1" />
                </x-ui.field>
                <x-ui.field label="Free (pieces)" name="freeQuantity" for="schemeFree">
                    <x-ui.input wire:model="freeQuantity" id="schemeFree" type="number" min="1" step="1" />
                </x-ui.field>
                <x-ui.field label="Starts" name="startsOn" for="schemeStarts">
                    <x-ui.input wire:model="startsOn" id="schemeStarts" type="date" />
                </x-ui.field>
                <x-ui.field label="Ends" name="endsOn" for="schemeEnds" help="Blank for no end">
                    <x-ui.input wire:model="endsOn" id="schemeEnds" type="date" />
                </x-ui.field>
                <x-ui.field label="Paid for by" name="vendorId" for="schemeVendor" class="sm:col-span-2" help="The company that refunds the free goods. Blank if the shop gives them itself.">
                    <x-ui.select wire:model="vendorId" id="schemeVendor">
                        <option value="">The shop (not claimed)</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="sm:col-span-2">
                    <x-ui.checkbox wire:model="isActive" label="Running (untick to pause it)" />
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'scheme-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit">Save</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="settle-claim" max-width="md">
        <form wire:submit="settle" class="p-6">
            @if ($settling)
                <h2 class="text-lg font-bold text-ink">Settle {{ $settling->number() }}</h2>
                <p class="mt-1 text-sm text-ink-muted">Claimed Rs {{ number_format($settling->claimed_amount, 2) }} from {{ $settling->vendor?->name }}.</p>
                <div class="mt-5 space-y-4">
                    <x-ui.field label="Amount the company accepted (Rs)" name="settleAmount" for="settleAmount" help="Less than claimed is fine; the rest is written off">
                        <x-ui.input wire:model="settleAmount" id="settleAmount" type="number" min="0" step="0.01" />
                    </x-ui.field>
                    <x-ui.field label="Settled as" for="settlement">
                        <x-ui.segmented name="settlement" wire:model.live="settlement" :options="['credit' => 'Credit on our account', 'cash' => 'Money paid to us']" label="Settled as" />
                    </x-ui.field>
                    @if ($settlement === 'cash')
                        <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
                    @endif
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'settle-claim' })">Back</x-ui.button>
                    <x-ui.button type="submit">Settle</x-ui.button>
                </div>
            @endif
        </form>
    </x-ui.modal>
</div>
