<?php

use App\Actions\JobCards;
use App\Enums\JobItemKind;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JobCard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * New / edit job card: who, the units brought in (with the details their kind
 * asks for), the technician, a promised date, a site visit for AC work, and
 * the warranty given on the work.
 */
new #[Layout('layouts.app')] #[Title('Job Card')] class extends Component
{
    use Toasts;

    public ?int $jobId = null;
    public string $customerId = '';
    public string $technicianId = '';
    public string $promisedOn = '';
    public bool $siteVisit = false;
    public string $siteAddress = '';
    public string $visitCharge = '';
    public string $warrantyDays = '';
    public string $nextServiceDays = '';
    public string $notes = '';
    /** @var list<array{id: ?int, kind: string, label: string, details: array<string, string>, problem: string, findings: string}> */
    public array $items = [];

    public function mount(): void
    {
        $job = ($id = request()->query('job')) ? JobCard::with('items')->findOrFail((int) $id) : null;

        if ($job !== null) {
            abort_unless($job->status->isOpen(), 404);
            $this->jobId = $job->id;
            $this->customerId = (string) $job->customer_id;
            $this->technicianId = (string) ($job->technician_id ?? '');
            $this->promisedOn = $job->promised_on?->toDateString() ?? '';
            $this->siteVisit = $job->site_visit;
            $this->siteAddress = $job->site_address ?? '';
            $this->visitCharge = (float) $job->visit_charge > 0 ? (string) (float) $job->visit_charge : '';
            $this->warrantyDays = (string) ($job->warranty_days ?? '');
            $this->nextServiceDays = (string) ($job->next_service_days ?? '');
            $this->notes = $job->notes ?? '';
            $this->items = $job->items->map(fn ($item) => ['id' => $item->id, 'kind' => $item->kind->value, 'label' => $item->label, 'details' => $item->details ?? [], 'problem' => $item->problem ?? '', 'findings' => $item->findings ?? ''])->all();
        } else {
            $this->addItem();
        }
    }

    public function addItem(): void
    {
        $last = end($this->items) ?: null;
        $this->items[] = ['id' => null, 'kind' => $last['kind'] ?? JobItemKind::Vehicle->value, 'label' => '', 'details' => [], 'problem' => '', 'findings' => ''];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
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

    public function save(): void
    {
        $this->validate(['customerId' => ['required', Rule::exists('customers', 'id')->where('shop_id', Auth::user()->shop_id)], 'promisedOn' => ['nullable', 'date'], 'visitCharge' => ['nullable', 'numeric', 'min:0'], 'warrantyDays' => ['nullable', 'integer', 'min:0', 'max:3650'], 'nextServiceDays' => ['nullable', 'integer', 'min:1', 'max:3650']], ['customerId.required' => 'Choose the customer.']);

        $fields = ['customer_id' => (int) $this->customerId, 'technician_id' => $this->technicianId !== '' ? (int) $this->technicianId : null, 'promised_on' => $this->promisedOn ?: null, 'site_visit' => $this->siteVisit, 'site_address' => $this->siteAddress, 'visit_charge' => $this->visitCharge, 'warranty_days' => $this->warrantyDays, 'next_service_days' => $this->nextServiceDays, 'notes' => $this->notes];
        $job = $this->jobId
            ? JobCards::update(JobCard::findOrFail($this->jobId), $fields, $this->items)
            : JobCards::open($fields, $this->items, Auth::user()->shop_id, Auth::id());

        $this->toastSuccess($this->jobId ? 'Saved.' : 'Job card '.$job->number().' opened.');
        $this->redirectRoute('job-cards.show', $job, navigate: true);
    }

    public function with(): array
    {
        return [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'staff' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ $jobId ? route('job-cards.show', $jobId) : route('job-cards.index') }}" back-label="{{ $jobId ? 'Job card' : 'Job Cards' }}">{{ $jobId ? 'Edit Job Card' : 'New Job Card' }}</x-ui.page-header>
    </x-slot>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Customer & job">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Customer" name="customerId" for="jobCustomer" required>
                    <x-ui.select wire:model.live="customerId" id="jobCustomer">
                        <option value="">Choose…</option>
                        <option value="__create__">+ New Customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Technician" name="technicianId" for="jobTechnician">
                    <x-ui.select wire:model="technicianId" id="jobTechnician">
                        <option value="">Not yet</option>
                        @foreach ($staff as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Promised for" name="promisedOn" for="jobPromised"><x-ui.input wire:model="promisedOn" id="jobPromised" type="date" /></x-ui.field>
                <x-ui.field label="Warranty on the work (days)" name="warrantyDays" for="jobWarranty" help="Starts when it's delivered"><x-ui.input wire:model="warrantyDays" id="jobWarranty" type="number" min="0" /></x-ui.field>
                <x-ui.field label="Next service in (days)" name="nextServiceDays" for="jobNextService" help="We remind the customer when it's due"><x-ui.input wire:model="nextServiceDays" id="jobNextService" type="number" min="1" placeholder="e.g. 90" /></x-ui.field>
            </div>
            <div class="mt-4 space-y-3">
                <x-ui.checkbox wire:model.live="siteVisit" label="Site visit (we go to the customer: AC, installation)" />
                @if ($siteVisit)
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="Address" name="siteAddress" for="jobSiteAddress" class="sm:col-span-2"><x-ui.input wire:model="siteAddress" id="jobSiteAddress" /></x-ui.field>
                        <x-ui.field label="Visit charge (Rs)" name="visitCharge" for="jobVisitCharge"><x-ui.input wire:model="visitCharge" id="jobVisitCharge" type="number" min="0" step="0.01" /></x-ui.field>
                    </div>
                @endif
                <x-ui.field label="Note" name="notes" for="jobNotes"><x-ui.input wire:model="notes" id="jobNotes" /></x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Brought in" data-job-items>
            <x-slot name="actions"><x-ui.button size="sm" variant="secondary" wire:click="addItem">+ Another unit</x-ui.button></x-slot>
            <div class="space-y-5">
                @foreach ($items as $i => $item)
                    @php($kind = \App\Enums\JobItemKind::tryFrom($item['kind']) ?? \App\Enums\JobItemKind::Other)
                    <div class="rounded-xl border border-line p-4" wire:key="job-item-{{ $i }}">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <x-ui.field label="Kind" name="items.{{ $i }}.kind" for="itemKind{{ $i }}">
                                <x-ui.select wire:model.live="items.{{ $i }}.kind" id="itemKind{{ $i }}">
                                    @foreach (\App\Enums\JobItemKind::cases() as $option)
                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                            <x-ui.field label="What it is" name="items.{{ $i }}.label" for="itemLabel{{ $i }}" class="sm:col-span-2"><x-ui.input wire:model="items.{{ $i }}.label" id="itemLabel{{ $i }}" placeholder="e.g. Honda CD70, Haier 1.5 ton split" /></x-ui.field>
                            @foreach ($kind->fields() as $key => $label)
                                <x-ui.field :label="$label" name="items.{{ $i }}.details.{{ $key }}" for="item{{ $i }}{{ $key }}"><x-ui.input wire:model="items.{{ $i }}.details.{{ $key }}" id="item{{ $i }}{{ $key }}" /></x-ui.field>
                            @endforeach
                            <x-ui.field label="Problem" name="items.{{ $i }}.problem" for="itemProblem{{ $i }}" class="sm:col-span-3"><x-ui.input wire:model="items.{{ $i }}.problem" id="itemProblem{{ $i }}" placeholder="What the customer says" /></x-ui.field>
                            @if ($jobId)
                                <x-ui.field label="Found" name="items.{{ $i }}.findings" for="itemFound{{ $i }}" class="sm:col-span-3"><x-ui.input wire:model="items.{{ $i }}.findings" id="itemFound{{ $i }}" /></x-ui.field>
                            @endif
                        </div>
                        @if (count($items) > 1)
                            <button type="button" wire:click="removeItem({{ $i }})" class="mt-2 text-xs font-semibold text-danger hover:underline">Remove this unit</button>
                        @endif
                    </div>
                @endforeach
                <x-ui.error :messages="$errors->get('items')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end"><x-ui.button type="submit" size="lg" data-save-job>{{ $jobId ? 'Save' : 'Open Job Card' }}</x-ui.button></div>
    </form>

    <livewire:customers.quick-create />
</div>
