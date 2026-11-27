<?php

namespace App\Services;

use App\Models\JobCard;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A job card to hand the customer when the unit comes in, or the estimate to approve. */
class JobCardPdfService
{
    public function download(JobCard $job, bool $estimate = false): StreamedResponse
    {
        $output = $this->render($job, $estimate);

        return response()->streamDownload(fn () => print ($output), $job->number().($estimate ? '-estimate' : '').'.pdf');
    }

    public function render(JobCard $job, bool $estimate = false): string
    {
        $job->loadMissing(['shop', 'customer', 'technician', 'items', 'lines', 'deposits']);

        return Pdf::loadView('pdf.job-card', [
            'job' => $job,
            'estimate' => $estimate,
            'deposits' => round($job->deposits->where('status', \App\Enums\RecordStatus::Active)->sum(fn ($d) => (float) $d->amount - (float) $d->refunded), 2),
        ])->setPaper('a5')->output();
    }
}
