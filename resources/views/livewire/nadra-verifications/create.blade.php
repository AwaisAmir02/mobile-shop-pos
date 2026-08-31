<?php

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
    use Toasts;

    public string $customerId = '';
    public string $phoneNumber = '';
    public string $cnicNumber = '';
    public string $amount = '';
    public string $discount = '0';

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

    public function totalCollected(): float
    {
        return max(0.0, (float) ($this->amount !== '' ? $this->amount : 0) - (float) $this->discount);
    }

    public function with(): array
    {
        return [
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastVerification' => $this->lastVerificationId ? NadraVerification::find($this->lastVerificationId) : null,
            'totalCollected' => $this->totalCollected(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'phoneNumber' => ['required', 'string', 'max:20'],
            'cnicNumber' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
        ]);

        $verification = NadraVerification::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'phone_number' => $this->phoneNumber,
            'cnic_number' => $this->cnicNumber,
            'amount' => $this->amount,
            'discount' => $this->discount,
            'total' => $this->totalCollected(),
        ]);

        $this->toastSuccess('NADRA verification recorded.');
        $this->lastVerificationId = $verification->id;
        $this->reset(['customerId', 'phoneNumber', 'cnicNumber', 'amount', 'discount']);
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
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">NADRA Verification</h1>
            <a href="{{ route('nadra-verifications.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-lg">
        @if ($lastVerification)
            <x-ui.card>
                <div class="flex flex-col items-center text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <p class="mt-3 text-sm text-slate-500">{{ $lastVerification->receiptNumber() }}</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastVerification->total, 2) }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $lastVerification->phone_number }} · {{ $lastVerification->cnic_number }}</p>

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
            <x-ui.card title="New NADRA Verification">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Customer" name="customerId" for="customerId" help="Optional — leave blank for a walk-in customer">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Phone Number" name="phoneNumber" for="phoneNumber">
                        <x-ui.input wire:model="phoneNumber" id="phoneNumber" type="tel" placeholder="03xx-xxxxxxx" autofocus />
                    </x-ui.field>

                    <x-ui.field label="CNIC Number" name="cnicNumber" for="cnicNumber">
                        <x-ui.input wire:model="cnicNumber" id="cnicNumber" placeholder="xxxxx-xxxxxxx-x" />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Amount" name="amount" for="amount">
                            <x-ui.input wire:model.live="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" />
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

    <livewire:customers.quick-create />
</div>
