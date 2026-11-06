<?php

namespace App\ReportQueries;

use App\Exports\TableExport;
use App\Models\Grn;
use App\Models\GrnLine;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Services\TableExportService;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class GrnReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected ?int $orderId = null,
    ) {}

    public function query(): Builder
    {
        return Grn::withoutGlobalScope(ExcludesCancelledScope::class)
            ->inCurrentBranch()
            ->with(['lines', 'order' => fn ($q) => $q->withoutGlobalScope(ExcludesCancelledScope::class)->with('vendor'), 'user'])
            ->when($this->from, fn ($q) => $q->whereDate('received_on', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('received_on', '<=', $this->to))
            ->when($this->orderId, fn ($q) => $q->where('purchase_order_id', $this->orderId));
    }

    public function filtersSummary(): string
    {
        if (! $this->from && ! $this->to) {
            return 'All records';
        }

        return 'Date range: '.($this->from ? Carbon::parse($this->from)->format('d M Y') : 'earliest').' – '.($this->to ? Carbon::parse($this->to)->format('d M Y') : 'latest');
    }

    public function summaryCards(): array
    {
        $active = (clone $this->query())->where('status', '!=', 'cancelled')->get();
        $count = $active->count();
        $value = round($active->flatMap->lines->sum(fn (GrnLine $line) => $line->cost_price !== null ? Quantity::money($line->cost_price, $line->quantity) : 0.0), 2);

        return [
            ['label' => 'Goods Received', 'value' => 'Rs '.number_format($value, 2), 'sub' => number_format($count).' '.str('receipt')->plural($count).', at cost'],
        ];
    }

    public function tableHeaders(): array
    {
        return ['GRN', 'PO', 'Vendor', 'Received', 'Product', 'Quantity', 'Unit', 'Cost Price', 'Expires', 'Status'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest('received_on')->latest('id')->get()->flatMap(function (Grn $grn) use ($forExcel) {
            $vendor = TableExportService::sanitizeCell($grn->order?->vendor?->name ?? '—');
            $status = $grn->isCancelled() ? 'Cancelled' : 'Active';

            return $grn->lines->map(function (GrnLine $line) use ($grn, $forExcel, $vendor, $status) {
                $name = TableExportService::sanitizeCell($line->product_name);
                $cost = $line->cost_price !== null ? (float) $line->cost_price : null;

                return $forExcel
                    ? [$grn->number(), $grn->order?->number() ?? '—', $vendor, TableExport::excelDate($grn->received_on), $name, $line->quantity, $line->unit->symbol(), $cost, TableExport::excelDate($line->expiry_date), $status]
                    : [$grn->number(), $grn->order?->number() ?? '—', $vendor, $grn->received_on->format('d M Y'), $name, Quantity::plain($line->quantity), $line->unit->symbol(), $cost !== null ? 'Rs '.number_format($cost, 2) : '—', $line->expiry_date?->format('d M Y') ?? '—', $status];
            });
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'string', 'string', 'date', 'string', 'quantity', 'string', 'currency', 'date', 'string'];
    }
}
