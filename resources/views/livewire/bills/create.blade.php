<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Services\BillPaymentReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Bills')] class extends Component
{
    use Toasts;

    public string $billCategoryId = '';
    public string $region = '';
    public string $billProviderId = '';
    public string $consumerNumber = '';
    public string $consumerName = '';
    public string $customerId = '';
    public string $amount = '';
    public string $fee = '0';
    public string $discount = '0';

    public ?int $lastPaymentId = null;

    public function mount(): void
    {
        BillCategory::ensureDefaultsExist();
        $this->billCategoryId = (string) (BillCategory::query()->orderBy('name')->value('id') ?? '');
    }

    public function updatedBillCategoryId(): void
    {
        $this->region = '';
        $this->billProviderId = '';
    }

    public function updatedRegion(): void
    {
        $this->billProviderId = '';
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

    public function totalCollected(): float
    {
        return max(0.0, (float) ($this->amount !== '' ? $this->amount : 0) + (float) $this->fee - (float) $this->discount);
    }

    public function with(): array
    {
        $providersQuery = fn () => BillProvider::query()
            ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId));

        return [
            'billCategories' => BillCategory::query()->orderBy('name')->get(),
            'regions' => $providersQuery()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
            'providers' => $providersQuery()
                ->when($this->region, fn ($query) => $query->where('region', $this->region))
                ->orderBy('name')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'lastPayment' => $this->lastPaymentId ? BillPayment::find($this->lastPaymentId) : null,
            'totalCollected' => $this->totalCollected(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'billCategoryId' => ['required', 'integer', Rule::exists('bill_categories', 'id')->where('shop_id', Auth::user()->shop_id)],
            'billProviderId' => ['required', 'integer', Rule::exists('bill_providers', 'id')->where('shop_id', Auth::user()->shop_id)->where('bill_category_id', $this->billCategoryId)],
            'consumerNumber' => ['required', 'string', 'max:100'],
            'consumerName' => ['required', 'string', 'max:255'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)],
            'amount' => ['required', 'numeric', 'min:0'],
            'fee' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
        ]);

        $payment = BillPayment::create([
            'user_id' => Auth::id(),
            'customer_id' => $this->customerId !== '' ? $this->customerId : null,
            'bill_category_id' => $this->billCategoryId,
            'bill_provider_id' => $this->billProviderId,
            'consumer_number' => $this->consumerNumber,
            'consumer_name' => $this->consumerName,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'discount' => $this->discount,
            'total' => $this->totalCollected(),
        ]);

        $this->toastSuccess('Bill payment recorded.');
        $this->lastPaymentId = $payment->id;
        $this->reset(['consumerNumber', 'consumerName', 'customerId', 'amount', 'fee', 'discount']);
    }

    public function logAnother(): void
    {
        $this->lastPaymentId = null;
    }

    public function downloadReceipt(BillPaymentReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BillPayment::findOrFail($this->lastPaymentId));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Bills</h1>
            <a href="{{ route('bills.history') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                History →
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-lg">
        @if ($lastPayment)
            <x-ui.card>
                <div class="flex flex-col items-center text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <p class="mt-3 text-sm text-slate-500">{{ $lastPayment->receiptNumber() }}</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($lastPayment->total, 2) }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $lastPayment->billProvider->name }} · {{ $lastPayment->consumer_number }}
                    </p>

                    <div class="mt-4 w-full space-y-1.5 rounded-lg border border-slate-200 p-4 text-left text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Bill Amount</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($lastPayment->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Service Charge</span>
                            <span class="font-medium text-slate-900">Rs {{ number_format($lastPayment->fee, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Discount</span>
                            <span class="font-medium text-slate-900">− Rs {{ number_format($lastPayment->discount, 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 font-semibold text-slate-900">
                            <span>Total Collected</span>
                            <span>Rs {{ number_format($lastPayment->total, 2) }}</span>
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
            <x-ui.card title="New Bill Payment">
                <form wire:submit="save" class="space-y-5">
                    <x-ui.field label="Category" name="billCategoryId" for="billCategoryId">
                        <x-ui.select wire:model.live="billCategoryId" id="billCategoryId">
                            @foreach ($billCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($regions->isNotEmpty())
                        <x-ui.field label="Region / Province" name="region" for="region" help="Optional — narrows the provider list">
                            <x-ui.select wire:model.live="region" id="region">
                                <option value="">All Regions</option>
                                @foreach ($regions as $regionOption)
                                    <option value="{{ $regionOption }}">{{ $regionOption }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endif

                    <x-ui.field label="Provider / Company" name="billProviderId" for="billProviderId">
                        <x-ui.select wire:model="billProviderId" id="billProviderId">
                            <option value="">Select a provider</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Consumer Number" name="consumerNumber" for="consumerNumber">
                        <x-ui.input wire:model="consumerNumber" id="consumerNumber" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Consumer Name" name="consumerName" for="consumerName">
                        <x-ui.input wire:model="consumerName" id="consumerName" />
                    </x-ui.field>

                    <x-ui.field label="Customer" name="customerId" for="customerId" help="Optional — leave blank for a walk-in payment">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Bill Amount" name="amount" for="amount">
                        <x-ui.input wire:model.live="amount" id="amount" type="number" min="0" step="0.01" class="text-lg" />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Service Charge" name="fee" for="fee">
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

    <livewire:customers.quick-create />
</div>
