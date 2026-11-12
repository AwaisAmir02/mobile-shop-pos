<?php

namespace App\Mail;

use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The "ending soon" banner, by email: sent once per renewal by billing:renewal-reminders. */
class RenewalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Shop $shop, public Carbon $renewsAt, public int $daysLeft)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('branding.name').' subscription ends in '.$this->daysLeft.' '.str('day')->plural($this->daysLeft));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renewal-reminder', with: [
            'lastDay' => $this->renewsAt->copy()->subDay(),
            'planName' => $this->shop->plan?->name,
        ]);
    }
}
