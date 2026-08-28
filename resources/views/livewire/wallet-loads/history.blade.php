<?php

use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use App\Services\WalletLoadReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load History')] class extends Component
{
    use WithPagination;

    public string $from = '';
    public string $to = '';
    public string $provider = '';
    public string $shopAccountId = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingProvider(): void
    {
        $this->resetPage();
    }

    public function updatingShopAccountId(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return WalletLoad::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->provider, fn ($query) => $query->where('provider', $this->provider))
            ->when($this->shopAccountId, fn ($query) => $query->where('shop_account_id', $this->shopAccountId));
    }

    public function with(): array
    {
        return [
            'loads' => $this->filteredQuery()->latest()->paginate(15),
            'totalLoaded' => $this->filteredQuery()->sum('amount'),
            'totalFees' => $this->filteredQuery()->sum('fee') - $this->filteredQuery()->sum('discount'),
            'providerTotals' => $this->filteredQuery()
                ->selectRaw('provider, SUM(amount) as total')
                ->groupBy('provider')
                ->orderByDesc('total')
                ->get(),
            'allProviders' => WalletProvider::query()->orderBy('name')->pluck('name'),
            'providersByName' => WalletProvider::query()->get()->keyBy('name'),
            'allShopAccounts' => ShopAccount::query()->orderBy('name')->get(),
            'shopAccountsById' => ShopAccount::query()->get()->keyBy('id'),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'provider', 'shopAccountId']);
    }

    public function downloadReceipt(int $loadId, WalletLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(WalletLoad::findOrFail($loadId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Wallet Load History</h1>
            <a href="{{ route('wallet-loads.index') }}" wire:navigate>
                <x-ui.button size="sm">New Wallet Load</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            <x-ui.field label="Provider" name="provider" for="provider" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="provider" id="provider">
                    <option value="">All Providers</option>
                    @foreach ($allProviders as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Send From" name="shopAccountId" for="shopAccountId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="shopAccountId" id="shopAccountId">
                    <option value="">All Accounts</option>
                    @foreach ($allShopAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($from || $to || $provider || $shopAccountId)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex gap-4">
            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Loaded</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalLoaded, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Net Service Fees</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalFees, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($providerTotals->isNotEmpty())
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($providerTotals as $row)
                <x-ui.stat :label="$row->provider" value="Rs {{ number_format($row->total, 2) }}" />
            @endforeach
        </div>
    @endif

    @if ($loads->isEmpty())
        <x-ui.empty-state
            title="No wallet loads yet"
            description="Completed wallet loads will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('wallet-loads.index') }}" wire:navigate>
                    <x-ui.button>New Wallet Load</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Provider', 'Recipient', 'Amount', 'Fee', 'Discount', 'Total', 'Send From', '']">
            @foreach ($loads as $load)
                <x-ui.table-row wire:key="load-{{ $load->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $load->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :src="$providersByName->get($load->provider)?->imageUrl()" :label="$load->provider" />
                            {{ $load->provider }}
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $load->account_name ?? '—' }}</span>
                            <span class="text-xs text-slate-400">{{ $load->account_number }}</span>
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->fee, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->discount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $shopAccountsById->get($load->shop_account_id)?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $load->id }})">
                            Download
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $loads->links() }}
        </div>
    @endif
</div>
