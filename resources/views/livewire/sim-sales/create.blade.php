<?php

use App\Enums\PaymentStatus;
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
    use \App\Livewire\Concerns\ChoosesPaymentSource;

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
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';

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

    public function updatedPaymentStatus(): void
    {
        if ($this->paymentStatus !== 'partial') {
            $this->amountPaid = '';
        }
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
            'paymentStatuses' => PaymentStatus::cases(),
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
            'paymentStatus' => ['required', Rule::enum(PaymentStatus::class)],
            'amountPaid' => ['nullable', 'numeric', 'min:0', 'required_if:paymentStatus,partial'],
        ]);

        $total = $this->totalCollected();

        $amountPaid = match ($this->paymentStatus) {
            'paid' => $total,
            'partial' => $this->amountPaid !== '' ? $this->amountPaid : 0,
            default => 0,
        };

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
            'total' => $total,
            'payment_status' => $this->paymentStatus,
            'amount_paid' => $amountPaid,
        ]);
        $this->notePaymentSource($sale, (float) $sale->amount_paid);

        $this->toastSuccess('SIM sale recorded.');
        $this->lastSaleId = $sale->id;
        $this->reset(['simType', 'simForm', 'isDuplicate', 'customerId', 'simNumber', 'amount', 'fee', 'discount', 'feeTouched', 'amountPaid']);
        $this->paymentStatus = 'paid';
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
        <x-ui.page-header back="{{ route('sim-sales.history') }}" back-label="SIM Sale History">SIM Sale</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastSale)
            <x-counter.receipt :number="$lastSale->receiptNumber()" :total="$lastSale->total">
                <span>{{ $lastSale->network }} · {{ $lastSale->sim_type->label() }} · {{ $lastSale->sim_form->label() }}</span>
                @if ($lastSale->is_duplicate)
                    <x-ui.badge variant="warning">Duplicate</x-ui.badge>
                @endif
                <span class="basis-full">{{ $lastSale->sim_number }}</span>
            </x-counter.receipt>
        @else
            <x-counter.form wire:submit="save">
                <x-counter.section title="SIM">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Network" name="network" for="network" required>
                            <x-ui.image-select
                                wire-model="network"
                                :options="$networkOptions"
                                id="network"
                                placeholder="Select a network"
                                with-color
                            />
                        </x-ui.field>

                        <x-ui.field label="Plan Type" name="simType" for="simType" required>
                            <x-ui.select wire:model="simType" id="simType">
                                @foreach ($simTypes as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="SIM Form" name="simForm" for="simForm" required>
                            <x-ui.select wire:model="simForm" id="simForm">
                                @foreach ($simForms as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="SIM Number" name="simNumber" for="simNumber" required help="The number being issued or activated">
                            <x-ui.input wire:model="simNumber" id="simNumber" placeholder="03xx-xxxxxxx" />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Leave blank for a walk-in sale">
                            <x-ui.select wire:model.live="customerId" id="customerId">
                                <option value="">Walk-in (no customer)</option>
                                <option value="__create__">+ New Customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <div class="mt-4">
                        <x-ui.checkbox wire:model="isDuplicate" label="Duplicate SIM (replacement for a lost SIM)" />
                    </div>
                </x-counter.section>

                <x-counter.section title="Amount">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Amount" name="amount" class="col-span-2 sm:col-span-1" for="amount" required>
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" />
                        </x-ui.field>

                        <x-ui.field label="Service Fee" name="fee" for="fee" help="Suggested from your commission %">
                            <x-ui.input wire:model.live="fee" id="fee" type="number" min="0" step="0.01" />
                        </x-ui.field>

                        <x-ui.field label="Discount" name="discount" for="discount">
                            <x-ui.input wire:model.live="discount" id="discount" type="number" min="0" step="0.01" />
                        </x-ui.field>
                    </div>
                </x-counter.section>

                <x-counter.section title="Payment">
                    <x-counter.payment-status :statuses="$paymentStatuses" :status="$paymentStatus" :paid-from="$paidFrom" />
                </x-counter.section>

                <x-slot:summary>
                    <x-counter.summary
                        :total="$totalCollected"
                        :lines="[
                            ['label' => 'Amount', 'value' => 'Rs '.number_format((float) $amount, 2)],
                            ['label' => 'Service Fee', 'value' => 'Rs '.number_format((float) $fee, 2)],
                            ['label' => 'Discount', 'value' => '− Rs '.number_format((float) $discount, 2)],
                        ]"
                    />
                </x-slot:summary>
            </x-counter.form>
        @endif
    </div>

    <livewire:customers.quick-create />
</div>
