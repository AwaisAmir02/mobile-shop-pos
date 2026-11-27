<?php

use App\Actions\CustomerDeposits;
use App\Actions\JobCards;
use App\Enums\JobCardStatus;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\Employee;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\ServiceItem;
use App\Services\JobCardPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One job card: where it stands, the units, the parts and labour priced on
 * it (the estimate), the customer's OK, deposits, and billing it at New Sale.
 */
new #[Layout('layouts.app')] #[Title('Job Card')] class extends Component
{
    use PicksPaymentMethod, Toasts;

    public JobCard $jobCard;

    /** @var list<array{kind: string, product_id: string, service_item_id: string, description: string, quantity: string, unit_price: string, employee_id: string}> */
    public array $lines = [];
    public string $partSearch = '';
    public string $approvedVia = 'In person';
    public string $cancelReason = '';
    public string $depositAmount = '';
    public ?string $readyLink = null;

    public function mount(JobCard $jobCard): void
    {
        $this->jobCard = $jobCard;
        $this->paymentMethod = 'cash';
        $this->loadLines();
    }

    protected function loadLines(): void
    {
        $this->lines = $this->jobCard->lines()->get()->map(fn ($l) => ['kind' => $l->kind->value, 'product_id' => (string) ($l->product_id ?? ''), 'service_item_id' => (string) ($l->service_item_id ?? ''), 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) (float) $l->unit_price, 'employee_id' => (string) ($l->employee_id ?? '')])->all();
    }

    public function addPart(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->lines[] = ['kind' => 'part', 'product_id' => (string) $product->id, 'service_item_id' => '', 'description' => $product->name, 'quantity' => '1', 'unit_price' => (string) $product->currentSellingPrice(), 'employee_id' => ''];
        $this->partSearch = '';
    }

    public function addLabour(?int $serviceItemId = null): void
    {
        $service = $serviceItemId ? ServiceItem::findOrFail($serviceItemId) : null;
        $this->lines[] = ['kind' => 'labour', 'product_id' => '', 'service_item_id' => (string) ($service?->id ?? ''), 'description' => $service?->name ?? '', 'quantity' => '1', 'unit_price' => (string) (float) ($service?->price ?? 0), 'employee_id' => (string) ($this->jobCard->technician_id ?? '')];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function saveLines(): void
    {
        $this->jobCard = JobCards::saveLines($this->jobCard, array_map(fn ($l) => $l + ['product_id' => null], $this->lines));
        $this->loadLines();
        $this->toastSuccess('Estimate saved: Rs '.number_format($this->jobCard->total(), 2).'.');
    }

    public function approve(): void
    {
        $this->jobCard = JobCards::approveEstimate($this->jobCard, $this->approvedVia);
        $this->toastSuccess('Customer\'s OK recorded.');
    }

    public function setStatus(string $status): void
    {
        $result = JobCards::move($this->jobCard, JobCardStatus::from($status), Auth::id());
        $this->jobCard = $result['job'];

        if ($status === JobCardStatus::Ready->value) {
            $this->readyLink = $result['notified'] === 'sent' ? null : JobCards::whatsappLink($this->jobCard->customer?->phone, JobCards::readyText($this->jobCard, Auth::user()->shop->invoiceDisplayName()));
            $result['notified'] === 'sent' ? $this->toastSuccess('Marked ready; the customer was told on WhatsApp.') : $this->toastSuccess('Marked ready. Tell the customer with the WhatsApp button.');
        }
    }

    public function cancel(): void
    {
        $this->jobCard = JobCards::cancel($this->jobCard, Auth::id(), $this->cancelReason);
        $this->dispatch('close-modal', name: 'cancel-job');
        $this->toastSuccess('Job cancelled.');
    }

    public function takeDeposit(): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Deposits), 404);
        [$method, $account] = $this->chosenPaymentMethod();
        CustomerDeposits::receive($this->jobCard->customer, (float) $this->depositAmount, now('Asia/Karachi')->toDateString(), $method, $account, Auth::id(), jobCard: $this->jobCard);
        $this->depositAmount = '';
        $this->dispatch('close-modal', name: 'job-deposit');
        $this->toastSuccess('Advance received.');
    }

    public function pdf(JobCardPdfService $pdf, bool $estimate = false): StreamedResponse
    {
        return $pdf->download($this->jobCard->fresh(), $estimate);
    }

    public function with(): array
    {
        $job = $this->jobCard->fresh(['customer', 'technician', 'items', 'lines', 'deposits', 'sale']);
        $search = trim($this->partSearch);

        return [
            'job' => $job,
            'open' => $job->status->isOpen(),
            'parts' => $search !== '' ? Product::query()->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(8)->get() : collect(),
            'labour' => ServiceItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']),
            'staff' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'depositsOn' => Auth::user()->shop->usesTool(ServiceTradeTool::Deposits),
            'paymentAccounts' => $this->paymentAccounts(),
            'heldAdvance' => round($job->deposits->sum(fn ($d) => $d->remaining()), 2),
            'today' => now('Asia/Karachi')->toDateString(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('job-cards.index') }}" back-label="Job Cards" :subtitle="$job->customer?->name.' · '.$job->unitsLabel()">{{ $job->number() }}</x-ui.page-header>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-center gap-2" data-job-status="{{ $job->status->value }}">
        <x-ui.badge :variant="$job->status === \App\Enums\JobCardStatus::Ready ? 'success' : ($open ? 'brand' : 'neutral')">{{ $job->status->label() }}</x-ui.badge>
        @if ($job->underWarrantyOn($today))
            <x-ui.badge variant="success">Under warranty until {{ $job->warranty_until->format('d M Y') }}</x-ui.badge>
        @endif
        @if ($open)
            @foreach (\App\Enums\JobCardStatus::settable() as $status)
                @if ($status !== $job->status)
                    <x-ui.button size="sm" variant="secondary" wire:click="setStatus('{{ $status->value }}')" data-set-status="{{ $status->value }}">{{ $status->label() }}</x-ui.button>
                @endif
            @endforeach
            <x-ui.button size="sm" variant="secondary" href="{{ route('job-cards.create', ['job' => $job->id]) }}">Edit details</x-ui.button>
            <x-ui.button size="sm" variant="accent" href="{{ route('sales.index', ['job_card' => $job->id]) }}" data-bill-job>Bill &amp; deliver</x-ui.button>
            <x-ui.button size="sm" variant="danger-ghost" x-on:click="$dispatch('open-modal', { name: 'cancel-job' })">Cancel job</x-ui.button>
        @elseif ($job->sale_id)
            <x-ui.button size="sm" variant="secondary" href="{{ route('sales.show', $job->sale_id) }}">{{ 'INV-'.str_pad((string) $job->sale_id, 6, '0', STR_PAD_LEFT) }}</x-ui.button>
        @endif
        <x-ui.button size="sm" variant="secondary" wire:click="pdf(false)">Job card PDF</x-ui.button>
        @if ($job->lines->isNotEmpty())
            <x-ui.button size="sm" variant="secondary" wire:click="pdf(true)">Estimate PDF</x-ui.button>
        @endif
    </div>

    @if ($job->service_due_on && ($dueLink = \App\Actions\JobCards::whatsappLink($job->customer?->phone, \App\Actions\ServiceReminders::serviceDueText($job, auth()->user()->shop->invoiceDisplayName()))))
        <x-ui.alert class="mb-5">Next service due {{ $job->service_due_on->format('d M Y') }}{{ $job->service_reminded_at ? ' · reminder sent '.$job->service_reminded_at->timezone('Asia/Karachi')->format('d M') : '' }}. <a href="{{ $dueLink }}" target="_blank" rel="noopener" class="font-semibold text-link hover:underline" data-service-due-link>Remind on WhatsApp</a></x-ui.alert>
    @endif
    @if ($readyLink)
        <x-ui.alert class="mb-5">Tell {{ $job->customer?->name }} it's ready: <a href="{{ $readyLink }}" target="_blank" rel="noopener" class="font-semibold text-link hover:underline" data-ready-link>Send on WhatsApp</a></x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Parts & labour" :description="'Estimate Rs '.number_format($job->total(), 2).($job->estimate_approved_at ? ' · approved '.$job->estimate_approved_at->timezone('Asia/Karachi')->format('d M, g:i A').' ('.$job->estimate_approved_via.')' : ' · not approved yet')" data-job-lines>
                @if ($open)
                    <div class="mb-4 flex flex-wrap items-start gap-3">
                        <div class="relative w-72">
                            <x-ui.input wire:model.live.debounce.250ms="partSearch" type="search" placeholder="Add a part from stock…" aria-label="Find a part" />
                            @if ($parts->isNotEmpty())
                                <ul class="absolute z-10 mt-1 w-full divide-y divide-line rounded-xl border border-line bg-surface shadow-float">
                                    @foreach ($parts as $part)
                                        <li><button type="button" wire:click="addPart({{ $part->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-sunken" data-add-part="{{ $part->id }}">{{ $part->name }} <span class="text-xs text-ink-faint">{{ $part->stockLabel() }} in stock · Rs {{ number_format($part->currentSellingPrice(), 0) }}</span></button></li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <x-ui.select x-on:change="$wire.addLabour($event.target.value || null); $event.target.value = ''" aria-label="Add labour" class="w-60">
                            <option value="">+ Add labour…</option>
                            <option value="0">Typed in</option>
                            @foreach ($labour as $service)
                                <option value="{{ $service->id }}">{{ $service->name }}{{ $service->price !== null ? ' · Rs '.number_format((float) $service->price, 0) : '' }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                @endif
                @if ($lines === [])
                    <p class="text-sm text-ink-faint">Nothing priced yet.</p>
                @else
                    <div class="space-y-2">
                        @foreach ($lines as $i => $line)
                            <div class="flex flex-wrap items-end gap-2 rounded-lg border border-line p-2" wire:key="line-{{ $i }}">
                                <x-ui.badge>{{ $line['kind'] === 'part' ? 'Part' : 'Labour' }}</x-ui.badge>
                                @if ($line['kind'] === 'part')
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{{ $line['description'] }}</span>
                                @else
                                    <input type="text" wire:model="lines.{{ $i }}.description" @disabled(! $open) placeholder="Labour" class="min-w-0 flex-1 rounded-lg border-line-strong bg-surface py-1.5 text-sm" />
                                @endif
                                <input type="number" min="0" step="any" wire:model="lines.{{ $i }}.quantity" @disabled(! $open) class="w-20 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm" aria-label="Quantity" />
                                <input type="number" min="0" step="0.01" wire:model="lines.{{ $i }}.unit_price" @disabled(! $open) class="w-28 rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm" aria-label="Price" />
                                @if ($line['kind'] === 'labour')
                                    <select wire:model="lines.{{ $i }}.employee_id" @disabled(! $open) class="w-36 rounded-lg border-line-strong bg-surface py-1.5 text-sm" aria-label="Done by">
                                        <option value="">Done by…</option>
                                        @foreach ($staff as $person)
                                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if ($open)
                                    <button type="button" wire:click="removeLine({{ $i }})" class="text-xs font-semibold text-danger hover:underline">Remove</button>
                                @endif
                                <x-ui.error :messages="$errors->get('lines.'.$i)" />
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ((float) $job->visit_charge > 0)
                    <p class="mt-2 text-sm text-ink-muted">Site visit: Rs {{ number_format((float) $job->visit_charge, 2) }}</p>
                @endif
                @if ($open)
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-ui.button wire:click="saveLines" data-save-lines>Save estimate</x-ui.button>
                        @if ($job->lines->isNotEmpty() && ! $job->estimate_approved_at)
                            <x-ui.select wire:model="approvedVia" class="w-40" aria-label="Approved how">
                                @foreach (['In person', 'By phone', 'On WhatsApp'] as $via)
                                    <option>{{ $via }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.button variant="secondary" wire:click="approve" data-approve>Customer approved</x-ui.button>
                        @endif
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Brought in">
                <div class="space-y-3">
                    @foreach ($job->items as $item)
                        <div class="text-sm">
                            <p class="font-semibold text-ink">{{ $item->label }} <span class="font-normal text-ink-faint">· {{ $item->kind->label() }}</span></p>
                            <p class="text-ink-muted">{{ collect($item->kind->fields())->map(fn ($label, $key) => filled($item->details[$key] ?? null) ? $label.': '.$item->details[$key] : null)->filter()->implode(' · ') }}</p>
                            @if ($item->problem)<p class="text-ink">Problem: {{ $item->problem }}</p>@endif
                            @if ($item->findings)<p class="text-ink">Found: {{ $item->findings }}</p>@endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Details">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Customer</dt><dd class="text-right text-ink"><a href="{{ route('customers.show', $job->customer_id) }}" class="hover:underline" wire:navigate>{{ $job->customer?->name }}</a><span class="block text-xs text-ink-faint">{{ $job->customer?->phone }}</span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Technician</dt><dd class="text-ink">{{ $job->technician?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Received</dt><dd class="text-ink">{{ $job->received_on->format('d M Y') }}</dd></div>
                    @if ($job->promised_on)<div class="flex justify-between gap-3"><dt class="text-ink-faint">Promised</dt><dd class="text-ink">{{ $job->promised_on->format('d M Y') }}</dd></div>@endif
                    @if ($job->site_visit)<div class="flex justify-between gap-3"><dt class="text-ink-faint">Site visit</dt><dd class="text-right text-ink">{{ $job->site_address }}</dd></div>@endif
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Warranty</dt><dd class="text-ink">{{ $job->warranty_days ? $job->warranty_days.' days' : '—' }}</dd></div>
                    @if ($job->status === \App\Enums\JobCardStatus::Cancelled)<div class="flex justify-between gap-3"><dt class="text-ink-faint">Cancelled</dt><dd class="text-right text-ink">{{ $job->cancel_reason }}</dd></div>@endif
                </dl>
            </x-ui.card>

            @if ($depositsOn)
                <x-ui.card title="Advance" data-job-advance>
                    <p class="text-sm text-ink">Held: <strong>Rs {{ number_format($heldAdvance, 2) }}</strong></p>
                    <p class="text-xs text-ink-faint">Used on the bill when the job is billed.</p>
                    @if ($open)
                        <x-ui.button size="sm" variant="secondary" class="mt-3" x-on:click="$dispatch('open-modal', { name: 'job-deposit' })">Take advance</x-ui.button>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>

    <x-ui.modal name="cancel-job" max-width="sm">
        <form wire:submit="cancel" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">Cancel {{ $job->number() }}</h2>
            <x-ui.field label="Why" name="cancelReason" for="cancelReason"><x-ui.input wire:model="cancelReason" id="cancelReason" /></x-ui.field>
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cancel-job' })">Keep it</x-ui.button><x-ui.button type="submit" variant="danger">Cancel job</x-ui.button></div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="job-deposit" max-width="sm">
        <form wire:submit="takeDeposit" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">Take an advance</h2>
            <x-ui.field label="Amount (Rs)" name="depositAmount" for="jobDepositAmount"><x-ui.input wire:model="depositAmount" id="jobDepositAmount" type="number" min="1" step="0.01" /></x-ui.field>
            <x-sales.payment-method-picker :accounts="$paymentAccounts" :value="$paymentMethod" amount="depositAmount" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'job-deposit' })">Cancel</x-ui.button><x-ui.button type="submit">Receive</x-ui.button></div>
        </form>
    </x-ui.modal>

    <livewire:shop-accounts.quick-create />
</div>
