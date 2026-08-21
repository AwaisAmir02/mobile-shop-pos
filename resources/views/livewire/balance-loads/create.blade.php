<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Services\BalanceLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Balance Load')] class extends Component
{
    use Toasts;

    public const NETWORKS = ['Jazz', 'Zong', 'Telenor', 'Ufone', 'Other'];

    public string $networkChoice = 'Jazz';
    public string $customNetwork = '';
    public string $phoneNumber = '';
    public string $amount = '';

    public ?int $lastLoadId = null;

    public function with(): array
    {
        return [
            'lastLoad' => $this->lastLoadId ? BalanceLoad::find($this->lastLoadId) : null,
        ];
    }

    public function save(): void
    {
        $rules = [
            'networkChoice' => ['required', 'in:'.implode(',', self::NETWORKS)],
            'phoneNumber' => ['nullable', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:1'],
        ];

        if ($this->networkChoice === 'Other') {
            $rules['customNetwork'] = ['required', 'string', 'max:255'];
        }

        $this->validate($rules);

        $load = BalanceLoad::create([
            'user_id' => Auth::id(),
            'network' => $this->networkChoice === 'Other' ? $this->customNetwork : $this->networkChoice,
            'phone_number' => $this->phoneNumber !== '' ? $this->phoneNumber : null,
            'amount' => $this->amount,
        ]);

        $this->toastSuccess('Balance loaded.');
        $this->lastLoadId = $load->id;
        $this->reset(['networkChoice', 'customNetwork', 'phoneNumber', 'amount']);
        $this->networkChoice = 'Jazz';
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
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastLoad->amount, 2) }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $lastLoad->network }}
                        @if ($lastLoad->phone_number)
                            · {{ $lastLoad->phone_number }}
                        @endif
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
            <x-ui.card title="New Balance Load">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Network" name="networkChoice" for="networkChoice">
                        <x-ui.select wire:model.live="networkChoice" id="networkChoice">
                            @foreach (self::NETWORKS as $network)
                                <option value="{{ $network }}">{{ $network }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($networkChoice === 'Other')
                        <x-ui.field label="Network Name" name="customNetwork" for="customNetwork">
                            <x-ui.input wire:model="customNetwork" id="customNetwork" autofocus />
                        </x-ui.field>
                    @endif

                    <x-ui.field label="Phone Number" name="phoneNumber" for="phoneNumber" help="Optional">
                        <x-ui.input wire:model="phoneNumber" id="phoneNumber" type="tel" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <x-ui.field label="Amount" name="amount" for="amount">
                        <x-ui.input wire:model="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" autofocus />
                    </x-ui.field>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
