<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Services\NadraVerificationReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('NADRA Verification')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts;

    public string $customerId = '';
    public string $phoneNumber = '';
    public string $cnicNumber = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';
    public bool $feeTouched = false;
    public string $paymentStatus = 'paid';
    public string $amountPaid = '';

    public ?int $lastVerificationId = null;

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
        $percent = (float) (Auth::user()->shop?->nadra_verification_commission_percent ?? 0);

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
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastVerification' => $this->lastVerificationId ? NadraVerification::find($this->lastVerificationId) : null,
            'totalCollected' => $this->totalCollected(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'phoneNumber' => ['required', 'string', 'max:20'],
            'cnicNumber' => ['required', 'string', 'max:20'],
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

        $verification = NadraVerification::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'phone_number' => $this->phoneNumber,
            'cnic_number' => $this->cnicNumber,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $total,
            'payment_status' => $this->paymentStatus,
            'amount_paid' => $amountPaid,
        ]);
        $this->notePaymentSource($verification, (float) $verification->amount_paid);

        $this->toastSuccess('NADRA verification recorded.');
        $this->lastVerificationId = $verification->id;
        $this->reset(['customerId', 'phoneNumber', 'cnicNumber', 'amount', 'fee', 'discount', 'feeTouched', 'amountPaid']);
        $this->paymentStatus = 'paid';
    }

    public function logAnother(): void
    {
        $this->lastVerificationId = null;
    }

    public function downloadReceipt(NadraVerificationReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(NadraVerification::findOrFail($this->lastVerificationId));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('nadra-verifications.history') }}" back-label="NADRA Verification History">NADRA Verification</x-ui.page-header>
    </x-slot>

    <div>
        @if ($lastVerification)
            <x-counter.receipt :number="$lastVerification->receiptNumber()" :total="$lastVerification->total">
                {{ $lastVerification->phone_number }} · {{ $lastVerification->cnic_number }}
            </x-counter.receipt>
        @else
            <x-counter.form wire:submit="save">
                <x-counter.section title="Verification">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Phone Number" name="phoneNumber" for="phoneNumber" required>
                            <x-ui.input wire:model="phoneNumber" id="phoneNumber" type="tel" placeholder="03xx-xxxxxxx" autofocus />
                        </x-ui.field>

                        <x-ui.field label="CNIC Number" name="cnicNumber" for="cnicNumber" required>
                            <x-ui.input wire:model="cnicNumber" id="cnicNumber" placeholder="xxxxx-xxxxxxx-x" />
                        </x-ui.field>

                        <x-ui.field label="Customer" name="customerId" for="customerId" help="Leave blank for a walk-in customer">
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
                        total-label="Total Collected"
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
