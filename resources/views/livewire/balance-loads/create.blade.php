<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\Network;
use App\Services\BalanceLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Balance Load')] class extends Component
{
    use Toasts;

    public string $networkChoice = '';
    public string $phoneNumber = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;

    public ?int $lastLoadId = null;

    public function mount(): void
    {
        Network::ensureDefaultsExist();
        $this->networkChoice = Network::query()->orderBy('name')->value('name') ?? '';
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
        $percent = (float) (Auth::user()->shop?->balance_load_commission_percent ?? 0);

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
            'networkOptions' => Network::query()->orderBy('name')->get()->map(fn (Network $network) => [
                'value' => $network->name,
                'label' => $network->name,
                'image' => $network->imageUrl(),
                'color' => $network->color,
            ])->all(),
            'selectedNetwork' => $this->networkChoice !== ''
                ? Network::query()->where('name', $this->networkChoice)->first()
                : null,
            'lastLoad' => $this->lastLoadId ? BalanceLoad::find($this->lastLoadId) : null,
            'totalCollected' => $this->totalCollected(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'networkChoice' => ['required', 'string', 'max:255'],
            'phoneNumber' => ['nullable', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:1'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
        ]);

        $load = BalanceLoad::create([
            'user_id' => Auth::id(),
            'network' => $this->networkChoice,
            'phone_number' => $this->phoneNumber !== '' ? $this->phoneNumber : null,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $this->totalCollected(),
        ]);

        $this->toastSuccess('Balance loaded.');
        $this->lastLoadId = $load->id;
        $this->reset(['phoneNumber', 'amount', 'fee', 'discount', 'feeTouched']);
    }

    public function logAnother(): void
    {
        $this->lastLoadId = null;
    }

    public function downloadReceipt(BalanceLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BalanceLoad::findOrFail($this->lastLoadId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Balance Load</h1>
            <a href="{{ route('balance-loads.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
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
                        <x-ui.color-dot :color="$selectedNetwork?->color" />
                        {{ $lastLoad->network }}
                        @if ($lastLoad->phone_number)
                            · {{ $lastLoad->phone_number }}
                        @endif
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
            <x-ui.card title="New Balance Load">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Network" name="networkChoice" for="networkChoice">
                        <x-ui.image-select
                            wire-model="networkChoice"
                            :options="$networkOptions"
                            id="networkChoice"
                            placeholder="Select a network"
                            with-color
                        />
                    </x-ui.field>

                    <x-ui.field label="Phone Number" name="phoneNumber" for="phoneNumber" help="Optional">
                        <x-ui.input wire:model="phoneNumber" id="phoneNumber" type="tel" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <x-ui.field label="Amount" name="amount" for="amount">
                        <x-ui.input wire:model.live="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" autofocus />
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
                        <span class="text-sm font-medium text-brand-700">Total Collected</span>
                        <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($totalCollected, 2) }}</span>
                    </div>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
