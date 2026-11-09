<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D13: tells the platform inbox a shop opened a ticket. Queued, so a slow or
 * broken mail server never holds up the shop's submit; the scheduler's
 * queue:work picks it up within a minute.
 */
class NewSupportTicketMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New support ticket '.$this->ticket->number().' from '.($this->ticket->shop?->name ?? 'a shop'));
    }

    public function content(): Content
    {
        // The queue worker has no signed-in user, so no shop scope gets in the way here.
        $ticket = $this->ticket->loadMissing(['shop', 'user', 'firstMessage.attachments']);

        return new Content(view: 'emails.support-ticket-opened', with: [
            'ticket' => $ticket,
            'firstMessage' => $ticket->firstMessage,
        ]);
    }
}
