<?php

namespace App\Services;

use App\Actions\CheckIns;
use App\Models\Customer;
use App\Models\Employee;
use App\Services\Marketing\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A member card or staff badge with the QR the desk scans (CheckIns::scan). */
class CheckInCardPdfService
{
    public function __construct(private readonly QrCodeService $qr) {}

    public function download(Customer|Employee $holder): StreamedResponse
    {
        $output = $this->render($holder);

        return response()->streamDownload(fn () => print ($output), ($holder instanceof Customer ? 'member-card-' : 'staff-badge-').$holder->id.'.pdf');
    }

    public function render(Customer|Employee $holder): string
    {
        $member = $holder instanceof Customer;
        $holder->loadMissing('shop');

        return Pdf::loadView('pdf.checkin-card', [
            'shop' => $holder->shop,
            'title' => $member ? 'Member card' : 'Staff badge',
            'name' => $holder->name,
            'number' => $member ? $holder->member_no : $holder->attendance_pin,
            'qr' => $this->qr->dataUri($member ? CheckIns::memberCode($holder) : CheckIns::staffCode($holder), 6),
        ])->setPaper([0, 0, 243, 153])->output();
    }
}
