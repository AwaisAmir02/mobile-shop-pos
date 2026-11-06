<?php

namespace App\ReportQueries;

use App\Enums\VendorInvoiceStatus;
use App\Exports\TableExport;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\VendorInvoice;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class VendorInvoiceReport implements ScreenReport
{
    public function __construct(
        protected string $status = '',
        protected string $vendorId = '',
    ) {}

    public function query(): Builder
    {
        return VendorInvoice::withoutGlobalScope(ExcludesCancelledScope::class)
            ->touchingCurrentBranch()
            ->with(['vendor', 'lines.stockIn', 'charges'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->vendorId, fn ($q) => $q->where('vendor_id', $this->vendorId));
    }

    public function filtersSummary(): string
    {
        return $this->status ? 'Status: '.VendorInvoiceStatus::from($this->status)->label() : 'All records';
    }

    public function summaryCards(): array
    {
        $invoices = (clone $this->query())->where('status', '!=', VendorInvoiceStatus::Cancelled->value)->get();
        $waiting = $invoices->filter->isFlagged()->count();

        return [
            ['label' => 'Needs Review', 'value' => number_format($waiting), 'sub' => str('invoice')->plural($waiting).' with differences'],
            ['label' => 'Owed on Approved', 'value' => 'Rs '.number_format($invoices->filter->isApproved()->sum(fn (VendorInvoice $invoice) => $invoice->amountOwed()), 2), 'sub' => 'Across their received batches'],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Invoice', "Vendor's No.", 'Vendor', 'Date', 'Billed', 'Owed', 'Status'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest('invoice_date')->latest('id')->get()->map(function (VendorInvoice $invoice) use ($forExcel) {
            $number = TableExportService::sanitizeCell($invoice->invoice_number);
            $vendor = TableExportService::sanitizeCell($invoice->vendor?->name ?? '—');
            $owed = $invoice->isApproved() ? $invoice->amountOwed() : null;

            return $forExcel
                ? [$invoice->number(), $number, $vendor, TableExport::excelDate($invoice->invoice_date), $invoice->billedTotal(), $owed, $invoice->status->label()]
                : [$invoice->number(), $number, $vendor, $invoice->invoice_date->format('d M Y'), 'Rs '.number_format($invoice->billedTotal(), 2), $owed !== null ? 'Rs '.number_format($owed, 2) : '—', $invoice->status->label()];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'string', 'string', 'date', 'currency', 'currency', 'string'];
    }
}
