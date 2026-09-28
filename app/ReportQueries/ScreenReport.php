<?php

namespace App\ReportQueries;

use Illuminate\Database\Eloquent\Builder;

/**
 * One implementation per screen the Reports builder can select — each one
 * is the single place that knows how to filter, summarize, and tabulate
 * that screen's data. The screen's own History Livewire component and the
 * Reports screen both construct the same class from their current filter
 * values and call into it, so there is exactly one copy of each screen's
 * query/summary logic rather than two that can drift apart.
 */
interface ScreenReport
{
    public function query(): Builder;

    /** A short human-readable description of the currently active filters, e.g. "Date range: 01 Sep 2026 – 30 Sep 2026". */
    public function filtersSummary(): string;

    /** @return array<int, array{label: string, value: string, sub?: string|null}> */
    public function summaryCards(): array;

    /** @return array<int, string> */
    public function tableHeaders(): array;

    /** @return array<int, array<int, mixed>> */
    public function tableRows(bool $forExcel): array;
}
