<?php

use App\Livewire\Concerns\Toasts;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use App\Services\WalletLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load')] class extends Component
{
    use Toasts;

    public string $provider = '';
    public string $accountNumber = '';
    public string $amount = '';

    public ?int $lastLoadId = null;

    public function mount(): void
    {
        WalletProvider::ensureDefaultsExist();
        $this->provider = WalletProvider::query()->orderBy('name')->value('name') ?? '';
    }

    public function with(): array
    {
        return [
            'providerOptions' => WalletProvider::query()->orderBy('name')->get()->map(fn (WalletProvider $provider) => [
                'value' => $provider->name,
                'label' => $provider->name,
                'image' => $provider->imageUrl(),
            ])->all(),
            'selectedProvider' => $this->provider !== ''
                ? WalletProvider::query()->where('name', $this->provider)->first()
                : null,
            'lastLoad' => $this->lastLoadId ? WalletLoad::find($this->lastLoadId) : null,
        ];
    }

    public function save(): void
    {
        $this->validate([
            'provider' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $load = WalletLoad::create([
            'user_id' => Auth::id(),
            'provider' => $this->provider,
            'account_number' => $this->accountNumber,
            'amount' => $this->amount,
        ]);

        $this->toastSuccess('Wallet load saved.');
        $this->lastLoadId = $load->id;
        $this->reset(['accountNumber', 'amount']);
    }

    public function logAnother(): void
    {
        $this->lastLoadId = null;
    }

    public function downloadReceipt(WalletLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(WalletLoad::findOrFail($this->lastLoadId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Wallet Load</h1>
            <a href="{{ route('wallet-loads.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-lg">
        @if ($lastLoad)
            <x-ui.card>
                <div class="flex flex-col items-center text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <p class="mt-3 text-sm text-slate-500">{{ $lastLoad->receiptNumber() }}</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastLoad->amount, 2) }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500">
                        <x-ui.thumbnail :src="$selectedProvider?->imageUrl()" :label="$lastLoad->provider" />
                        {{ $lastLoad->provider }} · {{ $lastLoad->account_number }}
                    </p>

                    <div class="mt-6 flex w-full gap-3">
                        <x-ui.button type="button" variant="secondary" wire:click="downloadReceipt" class="flex-1 justify-center">
                            Download Receipt
                        </x-ui.button>

                        <x-ui.button type="button" wire:click="logAnother" class="flex-1 justify-center">
                            Log Another
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @else
            <x-ui.card title="New Wallet Load">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Wallet Provider" name="provider" for="provider">
                        <x-ui.image-select
                            wire-model="provider"
                            :options="$providerOptions"
                            id="provider"
                            placeholder="Select a provider"
                        />
                    </x-ui.field>

                    <x-ui.field label="Account / Mobile Number" name="accountNumber" for="accountNumber">
                        <x-ui.input wire:model="accountNumber" id="accountNumber" type="tel" placeholder="03xx-xxxxxxx" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Amount" name="amount" for="amount">
                        <x-ui.input wire:model="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" />
                    </x-ui.field>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
