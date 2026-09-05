<?php

use App\Livewire\Concerns\Toasts;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use App\Services\WalletLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load')] class extends Component
{
    use Toasts;

    public string $provider = '';
    public string $accountName = '';
    public string $accountNumber = '';
    public string $shopAccountId = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;

    public ?int $lastLoadId = null;

    public function mount(): void
    {
        WalletProvider::ensureDefaultsExist();
        $this->provider = WalletProvider::query()->orderBy('name')->value('name') ?? '';
        $this->shopAccountId = (string) (ShopAccount::query()->orderBy('name')->value('id') ?? '');
    }

    public function updatedProvider(): void
    {
        if ($this->provider === '__create__') {
            $this->provider = '';
            $this->dispatch('open-modal', name: 'quick-create-wallet-provider');
        }
    }

    #[On('wallet-provider-created')]
    public function onWalletProviderCreated(string $providerName): void
    {
        $this->provider = $providerName;
    }

    public function updatedShopAccountId(): void
    {
        if ($this->shopAccountId === '__create__') {
            $this->shopAccountId = '';
            $this->dispatch('open-modal', name: 'quick-create-shop-account');
        }
    }

    #[On('shop-account-created')]
    public function onShopAccountCreated(int $shopAccountId): void
    {
        $this->shopAccountId = (string) $shopAccountId;
    }

    public function updatedAmount(): void
    {
        if (! $this->feeTouched) {
            $this->fee = $this->suggestedFee();
        }
    }

    public function updatedFee(): void
    {
        $this->feeTouched = true;
    }

    protected function suggestedFee(): string
    {
        $percent = (float) (Auth::user()->shop?->wallet_load_commission_percent ?? 0);

        if ($percent <= 0 || $this->amount === '') {
            return '0';
        }

        return number_format(((float) $this->amount) * $percent / 100, 2, '.', '');
    }

    public function totalCollected(): float
    {
        return max(0.0, (float) ($this->amount !== '' ? $this->amount : 0) + (float) $this->fee - (float) $this->discount);
    }

    public function with(): array
    {
        return [
            'providerOptions' => collect([
                ['value' => '__create__', 'label' => 'New Provider', 'image' => null, 'special' => true, 'modal' => 'quick-create-wallet-provider'],
            ])->concat(
                WalletProvider::query()->orderBy('name')->get()->map(fn (WalletProvider $provider) => [
                    'value' => $provider->name,
                    'label' => $provider->name,
                    'image' => $provider->imageUrl(),
                ])
            )->all(),
            'shopAccountOptions' => collect([
                ['value' => '__create__', 'label' => 'New Shop Account', 'image' => null, 'special' => true, 'modal' => 'quick-create-shop-account'],
            ])->concat(
                ShopAccount::query()->orderBy('name')->get()->map(fn (ShopAccount $account) => [
                    'value' => (string) $account->id,
                    'label' => $account->name,
                    'image' => null,
                ])
            )->all(),
            'selectedProvider' => $this->provider !== ''
                ? WalletProvider::query()->where('name', $this->provider)->first()
                : null,
            'lastLoad' => $this->lastLoadId ? WalletLoad::find($this->lastLoadId) : null,
            'totalCollected' => $this->totalCollected(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'provider' => ['required', 'string', 'max:255'],
            'accountName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:20'],
            'shopAccountId' => ['required', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:1'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
        ]);

        $load = WalletLoad::create([
            'user_id' => Auth::id(),
            'provider' => $this->provider,
            'account_name' => $this->accountName,
            'account_number' => $this->accountNumber,
            'shop_account_id' => $this->shopAccountId,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $this->totalCollected(),
        ]);

        $this->toastSuccess('Wallet load saved.');
        $this->lastLoadId = $load->id;
        $this->reset(['accountName', 'accountNumber', 'amount', 'fee', 'discount', 'feeTouched']);
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
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastLoad->total, 2) }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500">
                        <x-ui.thumbnail :src="$selectedProvider?->imageUrl()" :label="$lastLoad->provider" />
                        {{ $lastLoad->provider }} · {{ $lastLoad->account_name }} · {{ $lastLoad->account_number }}
                    </p>

                    <div class="mt-4 w-full space-y-1.5 rounded-lg border border-slate-200 p-4 text-left text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Amount Loaded</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($lastLoad->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Service Charge</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($lastLoad->fee, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Discount</span>
                            <span class="font-medium text-slate-900">− Rs {{ number_format($lastLoad->discount, 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 font-semibold text-slate-900">
                            <span>Total Collected</span>
                            <span>Rs {{ number_format($lastLoad->total, 2) }}</span>
                        </div>
                    </div>

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

                    <x-ui.field label="Account Name" name="accountName" for="accountName" help="Name on the recipient's wallet account">
                        <x-ui.input wire:model="accountName" id="accountName" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Account / Mobile Number" name="accountNumber" for="accountNumber">
                        <x-ui.input wire:model="accountNumber" id="accountNumber" type="tel" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <x-ui.field label="Amount" name="amount" for="amount" help="Amount being loaded into the recipient's account">
                        <x-ui.input wire:model.live="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" />
                    </x-ui.field>

                    <x-ui.field label="Send From" name="shopAccountId" for="shopAccountId" help="Which of the shop's own accounts is sending this">
                        <x-ui.image-select
                            wire-model="shopAccountId"
                            :options="$shopAccountOptions"
                            id="shopAccountId"
                            placeholder="Select a shop account"
                        />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Service Charge" name="fee" for="fee" help="Auto-suggested from your commission % — edit freely">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount" help="Optional">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>

                    <div class="flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3">
                        <span class="text-sm font-medium text-brand-700">Total Received from Customer</span>
                        <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($totalCollected, 2) }}</span>
                    </div>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    <livewire:wallet-providers.quick-create />
    <livewire:shop-accounts.quick-create />
</div>
