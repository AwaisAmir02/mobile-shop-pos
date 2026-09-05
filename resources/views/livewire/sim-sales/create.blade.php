<?php

use App\Enums\SimForm;
use App\Enums\SimType;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Network;
use App\Models\SimSale;
use App\Services\SimSaleReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('SIM Sale')] class extends Component
{
    use Toasts;

    public string $network = '';
    public string $simType = 'prepaid';
    public string $simForm = 'physical';
    public bool $isDuplicate = false;
    public string $customerId = '';
    public string $simNumber = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;

    public ?int $lastSaleId = null;

    public function mount(): void
    {
        Network::ensureDefaultsExist();
        $this->network = Network::query()->orderBy('name')->value('name') ?? '';
    }

    public function updatedCustomerId(): void
    {
        if ($this->customerId === '__create__') {
            $this->customerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');
        }
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId): void
    {
        $this->customerId = (string) $customerId;
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
        $percent = (float) (Auth::user()->shop?->sim_sale_commission_percent ?? 0);

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
            'simTypes' => SimType::cases(),
            'simForms' => SimForm::cases(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastSale' => $this->lastSaleId ? SimSale::find($this->lastSaleId) : null,
            'totalCollected' => $this->totalCollected(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'network' => ['required', 'string', 'max:255'],
            'simType' => ['required', Rule::enum(SimType::class)],
            'simForm' => ['required', Rule::enum(SimForm::class)],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'simNumber' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
        ]);

        $sale = SimSale::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'network' => $this->network,
            'sim_type' => $this->simType,
            'sim_form' => $this->simForm,
            'is_duplicate' => $this->isDuplicate,
            'sim_number' => $this->simNumber,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $this->totalCollected(),
        ]);

        $this->toastSuccess('SIM sale recorded.');
        $this->lastSaleId = $sale->id;
        $this->reset(['simType', 'simForm', 'isDuplicate', 'customerId', 'simNumber', 'amount', 'fee', 'discount', 'feeTouched']);
    }

    public function logAnother(): void
    {
        $this->lastSaleId = null;
    }

    public function downloadReceipt(SimSaleReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(SimSale::findOrFail($this->lastSaleId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">SIM Sale</h1>
            <a href="{{ route('sim-sales.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-lg">
        @if ($lastSale)
            <x-ui.card>
                <div class="flex flex-col items-center text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <p class="mt-3 text-sm text-slate-500">{{ $lastSale->receiptNumber() }}</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastSale->total, 2) }}</p>
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500">
                        {{ $lastSale->network }} · {{ $lastSale->sim_type->label() }} · {{ $lastSale->sim_form->label() }}
                        @if ($lastSale->is_duplicate)
                            <x-ui.badge variant="warning">Duplicate</x-ui.badge>
                        @endif
                    </p>
                    <p class="text-sm text-slate-500">{{ $lastSale->sim_number }}</p>

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
            <x-ui.card title="New SIM Sale">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Network" name="network" for="network">
                        <x-ui.image-select
                            wire-model="network"
                            :options="$networkOptions"
                            id="network"
                            placeholder="Select a network"
                            with-color
                        />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Plan Type" name="simType" for="simType">
                            <x-ui.select wire:model="simType" id="simType">
                                @foreach ($simTypes as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="SIM Form" name="simForm" for="simForm">
                            <x-ui.select wire:model="simForm" id="simForm">
                                @foreach ($simForms as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <x-ui.checkbox wire:model="isDuplicate" label="Duplicate SIM (replacement for a lost SIM)" />

                    <x-ui.field label="Customer" name="customerId" for="customerId" help="Optional — leave blank for a walk-in sale">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="SIM Number" name="simNumber" for="simNumber" help="The number being issued/activated">
                        <x-ui.input wire:model="simNumber" id="simNumber" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" for="amount">
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" />
                        </x-ui.field>

                        <x-ui.field label="Service Fee" name="fee" for="fee" help="Auto-suggested from your commission % — edit freely">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount" help="Optional">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>

                    <div class="flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3">
                        <span class="text-sm font-medium text-brand-700">Total</span>
                        <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($totalCollected, 2) }}</span>
                    </div>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    <livewire:customers.quick-create />
</div>
