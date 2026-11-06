<?php

namespace App\Livewire\Concerns;

use App\Ledger\LedgerPoster;
use App\Models\LedgerOutbox;
use App\ReportQueries\LedgerStatements;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What every ledger report shares: who gets in, catching up before showing
 * figures, and exporting. A screen supplies its title, filters and rows.
 */
trait LedgerReportScreen
{
    use GuardsExportSize;

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('ledger-reports') && Auth::user()->shop?->ledger_enabled, 403);
    }

    protected function catchUp(): void
    {
        $shop = Auth::user()->shop;

        if ($shop->ledger_ready_at !== null && $shop->ledger_frozen_at === null) {
            app(LedgerPoster::class)->drain($shop->id);
        }
    }

    protected function statements(): LedgerStatements
    {
        return new LedgerStatements(Auth::user()->shop);
    }

    protected function ledgerRunning(): bool
    {
        return Auth::user()->shop->ledger_ready_at !== null;
    }

    protected function failedPostings(): int
    {
        return LedgerOutbox::where('shop_id', Auth::user()->shop_id)->where('attempts', '>', 0)->count();
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        return $this->guardExportSize(fn () => $exportService->toPdf($this->reportTitle(), Auth::user()->shop->name, $this->reportFilters(), $this->reportHeaders(), $this->reportRows(false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        return $this->guardExportSize(fn () => $exportService->toExcel($this->reportTitle(), Auth::user()->shop->name, $this->reportFilters(), $this->reportHeaders(), $this->reportRows(true), $this->reportColumnTypes()));
    }

    protected function money(float $amount, bool $forExcel): float|string
    {
        return $forExcel ? $amount : 'Rs '.number_format($amount, 2);
    }

    protected function periodLabel(string $from, string $to): string
    {
        return 'From '.($from ?: 'the start').' to '.($to ?: 'today');
    }
}
