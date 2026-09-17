<?php

use App\Enums\BalanceLoadType;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\Customer;
use App\Models\Network;
use App\Services\BalanceLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Balance Load')] class extends Component
{
    use Toasts;

    public string $networkChoice = '';
    public string $loadType = 'balance';
    public string $phoneNumber = '';
    public string $customerId = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;
    public string $amountReceivedNow = '';

    public ?int $lastLoadId = null;

    public function mount(): void
    {
        Network::ensureDefaultsExist();
        $this->networkChoice = Network::query()->orderBy('name')->value('name') ?? '';
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

    protected function derivePaymentStatus(float $received, float $total): PaymentStatus
    {
        if ($received <= 0) {
            return PaymentStatus::Unpaid;
        }

        return $received >= $total ? PaymentStatus::Paid : PaymentStatus::Partial;
    }

    public function previewPaymentStatus(): PaymentStatus
    {
        $received = $this->amountReceivedNow !== '' ? (float) $this->amountReceivedNow : 0.0;

        return $this->derivePaymentStatus($received, $this->totalCollected());
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
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastLoad' => $this->lastLoadId ? BalanceLoad::find($this->lastLoadId) : null,
            'totalCollected' => $this->totalCollected(),
            'previewPaymentStatus' => $this->previewPaymentStatus(),
            'loadTypes' => BalanceLoadType::cases(),
        ];
    }

    public function save(): void
    {
        $total = $this->totalCollected();

        $this->validate([
            'networkChoice' => ['required', 'string', 'max:255'],
            'loadType' => ['required', Rule::enum(BalanceLoadType::class)],
            'phoneNumber' => ['nullable', 'string', 'max:20'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:1'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
            'amountReceivedNow' => ['nullable', 'numeric', 'min:0', 'max:'.$total],
        ]);

        $received = min($this->amountReceivedNow !== '' ? (float) $this->amountReceivedNow : 0.0, $total);

        $load = BalanceLoad::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'network' => $this->networkChoice,
            'load_type' => $this->loadType,
            'phone_number' => $this->phoneNumber !== '' ? $this->phoneNumber : null,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $total,
            'payment_status' => $this->derivePaymentStatus($received, $total),
            'amount_paid' => $received,
        ]);

        $this->toastSuccess('Balance loaded.');
        $this->lastLoadId = $load->id;
        $this->reset(['phoneNumber', 'customerId', 'amount', 'fee', 'discount', 'feeTouched', 'amountReceivedNow']);
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

    <div>
        @if ($lastLoad)
            <x-ui.card class="mx-auto max-w-lg">
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
                        {{ $lastLoad->network }} · {{ $lastLoad->load_type->label() }}
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
                        <div class="flex justify-between pt-1.5">
                            <span class="text-slate-500">Payment Status</span>
                            <span class="font-medium text-slate-900">{{ $lastLoad->payment_status->label() }}</span>
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
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.field label="Network" name="networkChoice" for="networkChoice">
                            <x-ui.image-select
                                wire-model="networkChoice"
                                :options="$networkOptions"
                                id="networkChoice"
                                placeholder="Select a network"
                                with-color
                            />
                        </x-ui.field>

                        <x-ui.field label="Type" name="loadType" for="loadType">
                            <x-ui.select wire:model="loadType" id="loadType">
                                @foreach ($loadTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Phone Number" name="phoneNumber" for="phoneNumber" help="Optional">
                            <x-ui.input wire:model="phoneNumber" id="phoneNumber" type="tel" placeholder="03xx-xxxxxxx" />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Optional — leave blank for a walk-in">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" for="amount">
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" autofocus />
                        </x-ui.field>

                        <x-ui.field label="Service Charge" name="fee" for="fee" help="Auto-suggested from your commission % — edit freely">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount" help="Optional">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Cash Received" name="amountReceivedNow" for="amountReceivedNow" help="How much cash did the customer hand you just now?">
                            <x-ui.input wire:model.live="amountReceivedNow" id="amountReceivedNow" type="number" min="0" step="0.01" placeholder="0" />
                        </x-ui.field>
                    </div>

                    <div class="rounded-lg bg-brand-50 px-4 py-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-brand-700">Total Collected</span>
                            <span class="text-lg font-semibold text-brand-900">Rs {{ number_format($totalCollected, 2) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span class="text-sm text-brand-700">Will be recorded as</span>
                            <x-ui.badge :variant="match($previewPaymentStatus->value) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }">
                                {{ $previewPaymentStatus->label() }}
                            </x-ui.badge>
                        </div>
                        @if ($amountReceivedNow !== '' && (float) $amountReceivedNow < $totalCollected)
                            <div class="mt-1 flex items-center justify-between text-sm">
                                <span class="text-slate-500">Still Owed by Customer</span>
                                <span class="font-medium text-amber-600">Rs {{ number_format($totalCollected - (float) $amountReceivedNow, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <x-ui.button type="submit" size="lg" class="w-full justify-center sm:w-auto" wire:loading.attr="disabled" wire:target="save">
                        Save
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    <livewire:customers.quick-create />
</div>
