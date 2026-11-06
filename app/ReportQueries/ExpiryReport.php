<?php

namespace App\ReportQueries;

use App\Exports\TableExport;
use App\Models\StockIn;
use App\Services\TableExportService;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Stock still on hand, by what its date says about it: expired, expiring
 * within $days, or with no date yet. Always shop-wide: batches are one shared
 * pool across branches. Valued at cost, from the batch's own cost first.
 */
class ExpiryReport implements ScreenReport
{
    public function __construct(protected int $days = BatchExpiry::SOON_DAYS) {}

    public function query(): Builder
    {
        return StockIn::query()->whereNotNull('product_id')->where('quantity_remaining', '>', 0)->with('product');
    }

    /** @return Collection<int, StockIn> */
    public function expired(): Collection
    {
        return $this->query()->where('expiry_date', '<', BatchExpiry::today())->orderBy('expiry_date')->orderBy('id')->get();
    }

    /** @return Collection<int, StockIn> */
    public function expiringSoon(): Collection
    {
        return $this->query()->whereBetween('expiry_date', [BatchExpiry::today(), BatchExpiry::soonCutoff($this->days)])->orderBy('expiry_date')->orderBy('id')->get();
    }

    /** @return Collection<int, StockIn> */
    public function undated(): Collection
    {
        return $this->query()->whereNull('expiry_date')->orderBy('stock_date')->orderBy('id')->get();
    }

    public static function valueAtCost(StockIn $batch): float
    {
        return Quantity::money($batch->cost_price ?? $batch->product?->cost_price ?? 0, $batch->quantity_remaining);
    }

    public static function totalAtCost(Collection $batches): float
    {
        return round($batches->sum(fn (StockIn $batch) => self::valueAtCost($batch)), 2);
    }

    public function filtersSummary(): string
    {
        return "Expiring within {$this->days} days";
    }

    public function summaryCards(): array
    {
        $expired = $this->expired();
        $soon = $this->expiringSoon();

        return [
            ['label' => 'Expired', 'value' => 'Rs '.number_format(self::totalAtCost($expired), 2), 'sub' => $expired->count().' '.str('batch')->plural($expired->count()).', at cost'],
            ['label' => 'Expiring Soon', 'value' => 'Rs '.number_format(self::totalAtCost($soon), 2), 'sub' => $soon->count().' '.str('batch')->plural($soon->count()).', next '.$this->days.' days, at cost'],
            ['label' => 'No Expiry Date', 'value' => number_format($this->undated()->count()), 'sub' => 'Batches to fill in'],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Status', 'Product', 'Received', 'Expires', 'Remaining', 'Unit', 'Value at Cost'];
    }

    public function tableRows(bool $forExcel): array
    {
        $rows = [];

        foreach (['Expired' => $this->expired(), 'Expiring soon' => $this->expiringSoon(), 'No date' => $this->undated()] as $status => $batches) {
            foreach ($batches as $batch) {
                $name = TableExportService::sanitizeCell($batch->product_name);

                $rows[] = $forExcel
                    ? [$status, $name, TableExport::excelDate($batch->stock_date), TableExport::excelDate($batch->expiry_date), $batch->quantity_remaining, $batch->unit->symbol(), self::valueAtCost($batch)]
                    : [$status, $name, $batch->stock_date->format('d M Y'), $this->dateCell($batch->expiry_date), Quantity::plain($batch->quantity_remaining), $batch->unit->symbol(), 'Rs '.number_format(self::valueAtCost($batch), 2)];
            }
        }

        return $rows;
    }

    public function columnTypes(): array
    {
        return ['string', 'string', 'date', 'date', 'quantity', 'string', 'currency'];
    }

    protected function dateCell(?CarbonInterface $date): string
    {
        return $date?->format('d M Y') ?? '—';
    }
}
