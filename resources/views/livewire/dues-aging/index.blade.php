<?php

use App\Enums\WholesaleTool;
use App\Livewire\Concerns\GuardsExportSize;
use App\Models\Customer;
use App\ReportQueries\DuesAging;
use App\Services\TableExportService;
use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dues aging (W5): who owes what, and for how long. Bills (sales and counter
 * services) and udhaar loans are two separate tables, never netted together.
 */
new #[Layout('layouts.app')] #[Title('Dues Aging')] class extends Component
{
    use GuardsExportSize;

    #[Url(except: 'bills')]
    public string $part = 'bills';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $area = '';

    #[Url(except: false)]
    public bool $overdueOnly = false;

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        $this->part = in_array($this->part, ['bills', 'udhaar'], true) ? $this->part : 'bills';
    }

    protected function aging(): DuesAging
    {
        return DuesAging::today();
    }

    /** Rows after the filters; the part (bills/udhaar) picks which figures show. */
    protected function filtered(Collection $rows): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $rows
            ->filter(fn (array $row) => $row[$this->part]['total'] > 0)
            ->when($this->area !== '', fn ($rows) => $rows->filter(fn (array $row) => (string) $row['customer']->area === $this->area))
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', [$row['customer']->name, $row['customer']->business_name, $row['customer']->phone, $row['customer']->area])), $search)))
            ->when($this->part === 'bills' && $this->overdueOnly, fn ($rows) => $rows->filter(fn (array $row) => $row['bills']['overdue'] > 0))
            ->sortByDesc(fn (array $row) => $row[$this->part]['total'])
            ->values();
    }

    public function with(): array
    {
        $aging = $this->aging();
        $all = $aging->rows();
        $rows = $this->filtered($all);

        return [
            'asOf' => \Carbon\Carbon::parse($aging->asOf),
            'all' => DuesAging::totals($all),
            'rows' => $rows,
            'shown' => DuesAging::totals($rows),
            'areas' => Customer::query()->whereNotNull('area')->where('area', '!=', '')->distinct()->orderBy('area')->pluck('area'),
            'overdueCustomers' => $all->filter(fn (array $row) => $row['bills']['overdue'] > 0)->count(),
            'chequesInHand' => Auth::user()->shop?->usesTool(WholesaleTool::Cheques) ? \App\Actions\Cheques::inHand() : null,
        ];
    }

    protected function headers(): array
    {
        return array_merge(['Customer', 'Area', 'Phone', 'Terms', 'Owed'], DuesAging::BUCKETS, $this->part === 'bills' ? ['Overdue'] : []);
    }

    protected function exportRows(bool $forExcel): array
    {
        $money = fn (float $v) => $forExcel ? $v : 'Rs '.number_format($v, 2);

        return $this->filtered($this->aging()->rows())->map(function (array $row) use ($money) {
            $part = $row[$this->part];
            $customer = $row['customer'];

            return array_merge([
                $customer->displayName(),
                (string) $customer->area,
                (string) $customer->phone,
                $customer->payment_terms_days ? $customer->payment_terms_days.' days' : '',
                $money($part['total']),
            ], array_map($money, $part['buckets']), $this->part === 'bills' ? [$money($part['overdue'])] : []);
        })->all();
    }

    protected function title(): string
    {
        return $this->part === 'bills' ? 'Dues aging - bills' : 'Dues aging - udhaar';
    }

    protected function summary(): string
    {
        return collect([
            'As of '.\Carbon\Carbon::parse($this->aging()->asOf)->format('d M Y'),
            $this->area !== '' ? 'Area: '.$this->area : null,
            $this->search !== '' ? 'Search: '.$this->search : null,
            $this->part === 'bills' && $this->overdueOnly ? 'Overdue only' : null,
        ])->filter()->implode(' · ');
    }

    public function exportPdf(TableExportService $export): ?StreamedResponse
    {
        return $this->guardExportSize(fn () => $export->toPdf($this->title(), Auth::user()->shop->name, $this->summary(), $this->headers(), $this->exportRows(false)));
    }

    public function exportExcel(TableExportService $export): ?BinaryFileResponse
    {
        $types = array_merge(['string', 'string', 'string', 'string', 'currency'], array_fill(0, 4, 'currency'), $this->part === 'bills' ? ['currency'] : []);

        return $this->guardExportSize(fn () => $export->toExcel($this->title(), Auth::user()->shop->name, $this->summary(), $this->headers(), $this->exportRows(true), $types));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('reports.index') }}" back-label="Reports" subtitle="Who owes the shop, and for how long, as of {{ $asOf->format('d M Y') }}">Dues Aging</x-ui.page-header>
    </x-slot>

    <x-ui.stat-row>
        <x-ui.stat label="Bills owed" value="Rs {{ number_format($all['bills']['total'], 2) }}" sub="Unpaid sales and services" />
        <x-ui.stat label="Overdue bills" value="Rs {{ number_format($all['bills']['overdue'], 2) }}" :sub="$overdueCustomers.' '.\Illuminate\Support\Str::plural('customer', $overdueCustomers).' past their due date'" />
        <x-ui.stat label="Udhaar owed" value="Rs {{ number_format($all['udhaar']['total'], 2) }}" sub="Loans given, not yet repaid" />
        @if ($chequesInHand !== null)
            <x-ui.stat label="Cheques in hand" value="Rs {{ number_format($chequesInHand, 2) }}" sub="Received, not cleared yet" />
        @endif
        <x-ui.stat label="Over 90 days" value="Rs {{ number_format($all['bills']['buckets'][3] + $all['udhaar']['buckets'][3], 2) }}" :sub="'Rs '.number_format($all['bills']['buckets'][3], 2).' bills · Rs '.number_format($all['udhaar']['buckets'][3], 2).' udhaar'" />
    </x-ui.stat-row>

    <x-ui.filter-bar :active="($search !== '' ? 1 : 0) + ($area !== '' ? 1 : 0) + ($overdueOnly ? 1 : 0)">
        <x-ui.field label="Show" for="aging-part" class="w-full sm:w-auto">
            <x-ui.segmented name="aging-part" wire:model.live="part" :options="['bills' => 'Bills', 'udhaar' => 'Udhaar loans']" label="Show" />
        </x-ui.field>
        <x-ui.field label="Search" for="aging-search" class="w-full sm:w-60">
            <x-ui.input wire:model.live.debounce.400ms="search" id="aging-search" type="search" placeholder="Name, business or phone…" />
        </x-ui.field>
        @if ($areas->isNotEmpty())
            <x-ui.field label="Area / route" for="aging-area" class="w-full sm:w-52">
                <x-ui.select wire:model.live="area" id="aging-area">
                    <option value="">All areas</option>
                    @foreach ($areas as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif
        @if ($part === 'bills')
            <div class="flex items-end pb-2">
                <x-ui.checkbox wire:model.live="overdueOnly" label="Overdue only" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.export-dropdown />
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($rows->isEmpty())
        <x-ui.empty-state
            :title="$part === 'bills' ? 'No unpaid bills' : 'No udhaar owed'"
            :description="$search !== '' || $area !== '' || $overdueOnly ? 'Nothing matches these filters.' : 'Nobody owes the shop anything here right now.'"
        />
    @else
        <x-ui.table stack :headers="array_merge(['Customer', 'Owed'], \App\ReportQueries\DuesAging::BUCKETS, $part === 'bills' ? ['Overdue'] : [])" data-aging-table="{{ $part }}">
            @foreach ($rows as $row)
                @php($figures = $row[$part])
                <x-ui.table-row wire:key="aging-{{ $part }}-{{ $row['customer']->id }}" data-aging-row="{{ $row['customer']->id }}">
                    <x-ui.table-cell primary>
                        <a href="{{ route('customers.show', $row['customer']) }}" wire:navigate class="font-semibold text-ink hover:text-link hover:underline">{{ $row['customer']->displayName() }}</a>
                        <span class="block text-xs text-ink-faint">{{ collect([$row['customer']->area, $row['customer']->phone, $row['customer']->payment_terms_days ? $row['customer']->payment_terms_days.'-day terms' : null, $figures['oldest'] ? 'oldest '.\Carbon\Carbon::parse($figures['oldest'])->format('d M Y') : null])->filter()->implode(' · ') }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Owed" class="font-bold tabular-nums text-ink">Rs {{ number_format($figures['total'], 2) }}</x-ui.table-cell>
                    @foreach ($figures['buckets'] as $i => $amount)
                        <x-ui.table-cell :label="\App\ReportQueries\DuesAging::BUCKETS[$i]" @class(['tabular-nums', 'text-ink-faint' => $amount <= 0, 'font-semibold text-danger' => $i === 3 && $amount > 0])>{{ $amount > 0 ? 'Rs '.number_format($amount, 2) : '—' }}</x-ui.table-cell>
                    @endforeach
                    @if ($part === 'bills')
                        <x-ui.table-cell label="Overdue" @class(['tabular-nums', 'text-ink-faint' => $figures['overdue'] <= 0, 'font-semibold text-danger' => $figures['overdue'] > 0])>{{ $figures['overdue'] > 0 ? 'Rs '.number_format($figures['overdue'], 2) : '—' }}</x-ui.table-cell>
                    @endif
                </x-ui.table-row>
            @endforeach
            <x-ui.table-row data-aging-total>
                <x-ui.table-cell primary class="font-bold text-ink">Total ({{ $rows->count() }})</x-ui.table-cell>
                <x-ui.table-cell label="Owed" class="font-bold tabular-nums text-ink">Rs {{ number_format($shown[$part]['total'], 2) }}</x-ui.table-cell>
                @foreach ($shown[$part]['buckets'] as $i => $amount)
                    <x-ui.table-cell :label="\App\ReportQueries\DuesAging::BUCKETS[$i]" class="font-bold tabular-nums text-ink">Rs {{ number_format($amount, 2) }}</x-ui.table-cell>
                @endforeach
                @if ($part === 'bills')
                    <x-ui.table-cell label="Overdue" class="font-bold tabular-nums text-ink">Rs {{ number_format($shown['bills']['overdue'], 2) }}</x-ui.table-cell>
                @endif
            </x-ui.table-row>
        </x-ui.table>
    @endif
</div>
