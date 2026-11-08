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
    use \App\Livewire\Concerns\ChoosesPaymentSource;

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
        $this->notePaymentSource($load, (float) $load->amount_paid);

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
        <x-ui.page-header back="{{ route('balance-loads.history') }}" back-label="Balance Load History">Balance Load</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastLoad)
            <x-counter.receipt
                :number="$lastLoad->receiptNumber()"
                :total="$lastLoad->total"
                :lines="[
                    ['label' => 'Amount Loaded', 'value' => 'Rs '.number_format($lastLoad->amount, 2)],
                    ['label' => 'Service Charge', 'value' => 'Rs '.number_format($lastLoad->fee, 2)],
                    ['label' => 'Discount', 'value' => '− Rs '.number_format($lastLoad->discount, 2)],
                    ['label' => 'Total Collected', 'value' => 'Rs '.number_format($lastLoad->total, 2), 'total' => true],
                    ['label' => 'Payment Status', 'value' => $lastLoad->payment_status->label()],
                ]"
            >
                <x-ui.color-dot :color="$selectedNetwork?->color" />
                {{ $lastLoad->network }} · {{ $lastLoad->load_type->label() }}
                @if ($lastLoad->phone_number)
                    · {{ $lastLoad->phone_number }}
                @endif
            </x-counter.receipt>
        @else
            <x-counter.form wire:submit="save">
                <x-counter.section title="Load">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Network" name="networkChoice" for="networkChoice" required>
                            <x-ui.image-select
                                wire-model="networkChoice"
                                :options="$networkOptions"
                                id="networkChoice"
                                placeholder="Select a network"
                                with-color
                            />
                        </x-ui.field>

                        <x-ui.field label="Type" name="loadType" for="loadType" required>
                            <x-ui.select wire:model="loadType" id="loadType">
                                @foreach ($loadTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Phone Number" name="phoneNumber" for="phoneNumber">
                            <x-ui.input wire:model="phoneNumber" id="phoneNumber" type="tel" placeholder="03xx-xxxxxxx" />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Leave blank for a walk-in">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Amount">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" class="col-span-2 sm:col-span-1" for="amount" required>
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="1" step="0.01" class="text-lg" autofocus />
                        </x-ui.field>

                        <x-ui.field label="Service Charge" name="fee" for="fee" help="Suggested from your commission %">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Cash Received" name="amountReceivedNow" for="amountReceivedNow" help="How much cash did the customer hand you just now?">
                            <x-ui.input wire:model.live="amountReceivedNow" id="amountReceivedNow" type="number" min="0" step="0.01" placeholder="0" />
                        </x-ui.field>

                        <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
                    </div>
                </x-counter.section>

                <x-slot:summary>
                    <x-counter.summary
                        total-label="Total Collected"
                        :total="$totalCollected"
                        :lines="[
                            ['label' => 'Amount', 'value' => 'Rs '.number_format((float) $amount, 2)],
                            ['label' => 'Service Charge', 'value' => 'Rs '.number_format((float) $fee, 2)],
                            ['label' => 'Discount', 'value' => '− Rs '.number_format((float) $discount, 2)],
                        ]"
                        :status="$previewPaymentStatus"
                        :owed="$amountReceivedNow !== '' && (float) $amountReceivedNow < $totalCollected ? $totalCollected - (float) $amountReceivedNow : null"
                    />
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:customers.quick-create />
</div>
