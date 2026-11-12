<?php

namespace App\Mail;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the platform inbox a shop signed up by itself. */
class NewSignupMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Shop $shop, public User $owner)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New signup: '.$this->shop->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-signup', with: [
            'plan' => $this->shop->plan,
        ]);
    }
}
