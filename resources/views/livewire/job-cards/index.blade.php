<?php

use App\Enums\JobCardStatus;
use App\Models\JobCard;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Job Cards (repair workshops): every job, filtered by where it stands, and
 * found by customer, registration or serial number (for warranty claims too).
 */
new #[Layout('layouts.app')] #[Title('Job Cards')] class extends Component
{
    use WithPagination;

    #[Url(except: 'open')]
    public string $filter = 'open';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $today = now('Asia/Karachi')->toDateString();
        $search = trim($this->search);

        $jobs = JobCard::query()
            ->with(['customer', 'technician', 'items', 'lines'])
            ->when($this->filter === 'open', fn ($q) => $q->whereIn('status', JobCardStatus::openValues()))
            ->when($this->filter === 'ready', fn ($q) => $q->where('status', JobCardStatus::Ready->value))
            ->when($this->filter === 'delivered', fn ($q) => $q->where('status', JobCardStatus::Delivered->value))
            ->when($this->filter === 'warranty', fn ($q) => $q->where('warranty_until', '>=', $today))
            ->when($this->filter === 'service_due', fn ($q) => $q->where('status', JobCardStatus::Delivered->value)->whereNotNull('service_due_on')->where('service_due_on', '<=', $today))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'))
                ->orWhereHas('items', fn ($i) => $i->where('label', 'like', '%'.$search.'%')->orWhere('details', 'like', '%'.$search.'%'))
                ->orWhere('id', ltrim(preg_replace('/^JC-/i', '', $search), '0') ?: 0)))
            ->latest('id')
            ->paginate(25);

        return ['jobs' => $jobs, 'today' => $today];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Units brought in for repair, and where each job stands">Job Cards</x-ui.page-header>
    </x-slot>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['open' => 'Open', 'ready' => 'Ready', 'delivered' => 'Delivered', 'warranty' => 'Under warranty', 'service_due' => 'Service due', 'all' => 'All'] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')" @class(['rounded-full border px-3 py-1.5 text-sm font-semibold', 'border-link bg-primary-soft text-link' => $filter === $value, 'border-line text-ink-muted hover:border-line-strong' => $filter !== $value])>{{ $label }}</button>
        @endforeach
        <x-ui.input wire:model.live.debounce.300ms="search" type="search" placeholder="Customer, reg. no., serial, JC no." class="w-64" aria-label="Search jobs" />
        <x-ui.button href="{{ route('job-cards.create') }}" class="ml-auto" data-new-job>New Job Card</x-ui.button>
    </div>

    @if ($jobs->isEmpty())
        <x-ui.empty-state title="No job cards here" description="Open a job card when a unit comes in." />
    @else
        <x-ui.table :headers="['Job', 'Customer', 'Units', 'Technician', 'Status', 'Total']" data-job-list>
            @foreach ($jobs as $job)
                <tr wire:key="job-{{ $job->id }}">
                    <td class="px-4 py-2.5 text-sm"><a href="{{ route('job-cards.show', $job) }}" class="font-semibold text-link hover:underline" wire:navigate>{{ $job->number() }}</a><span class="block text-xs text-ink-faint">{{ $job->received_on->format('d M') }}@if ($job->promised_on) · due {{ $job->promised_on->format('d M') }}@endif</span></td>
                    <td class="px-4 py-2.5 text-sm text-ink">{{ $job->customer?->name }}</td>
                    <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $job->unitsLabel() }}</td>
                    <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $job->technician?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-sm"><x-ui.badge :variant="match ($job->status) { \App\Enums\JobCardStatus::Ready => 'success', \App\Enums\JobCardStatus::Delivered => 'neutral', \App\Enums\JobCardStatus::Cancelled => 'danger', default => 'brand' }">{{ $job->status->label() }}</x-ui.badge>@if ($job->underWarrantyOn($today)) <x-ui.badge variant="success">Warranty</x-ui.badge>@endif</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums text-ink">Rs {{ number_format($job->total(), 0) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $jobs->links() }}</div>
    @endif
</div>
