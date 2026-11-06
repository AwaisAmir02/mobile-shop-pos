<?php

namespace App\ReportQueries;

use App\Enums\QuantityUnit;
use App\Enums\WriteOffReason;
use App\Exports\TableExport;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockWriteOff;
use App\Services\TableExportService;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class StockWriteOffReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $reason = '',
        protected bool $includeCancelled = false,
    ) {}

    public function query(): Builder
    {
        $query = StockWriteOff::query()
            ->with('user')
            ->when($this->from, fn ($q) => $q->whereDate('written_off_on', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('written_off_on', '<=', $this->to))
            ->when($this->reason, fn ($q) => $q->where('reason', $this->reason));

        return $this->includeCancelled ? $query->withoutGlobalScope(ExcludesCancelledScope::class) : $query;
    }

    /** Totals never count a cancelled write-off, whatever the list shows. */
    public function totalCost(): float
    {
        return (float) $this->query()->where('status', '!=', 'cancelled')->sum('total_cost');
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->reason) {
            $parts[] = 'Reason: '.(WriteOffReason::tryFrom($this->reason)?->label() ?? $this->reason);
        }

        return $parts ? implode('; ', $parts) : 'All records';
    }

    public function summaryCards(): array
    {
        $count = $this->query()->where('status', '!=', 'cancelled')->count();

        return [
            ['label' => 'Written Off at Cost', 'value' => 'Rs '.number_format($this->totalCost(), 2), 'sub' => number_format($count).' '.str('write-off')->plural($count)],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Date', 'Product', 'Quantity', 'Unit', 'Reason', 'Note', 'Cost', 'Status', 'Entered By'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest('written_off_on')->latest('id')->get()->map(function (StockWriteOff $writeOff) use ($forExcel) {
            $name = TableExportService::sanitizeCell($writeOff->product_name);
            $note = TableExportService::sanitizeCell($writeOff->note ?? '');
            $by = TableExportService::sanitizeCell($writeOff->user?->name ?? '—');
            $cost = $writeOff->total_cost !== null ? (float) $writeOff->total_cost : null;
            $status = $writeOff->isCancelled() ? 'Cancelled' : 'Active';

            return $forExcel
                ? [TableExport::excelDate($writeOff->written_off_on), $name, $writeOff->quantity, $writeOff->unit->symbol(), $writeOff->reason->label(), $note, $cost, $status, $by]
                : [$writeOff->written_off_on->format('d M Y'), $name, Quantity::plain($writeOff->quantity), $writeOff->unit->symbol(), $writeOff->reason->label(), $note ?: '—', $cost !== null ? 'Rs '.number_format($cost, 2) : '—', $status, $by];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['date', 'string', 'quantity', 'string', 'string', 'string', 'currency', 'string', 'string'];
    }
}
