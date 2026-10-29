<?php

namespace App\Mail;

use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BackupFailedMail extends Mailable
{
    use Queueable;

    public function __construct(public BackupRun $run, public string $reason = 'failed') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reason === 'stale'
            ? config('branding.name').' backups have not run for over '.config('backup.stale_after_hours').' hours'
            : config('branding.name').' backup failed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.backup-failed', with: [
            'lastSuccess' => BackupRun::lastSuccessful(),
        ]);
    }
}
