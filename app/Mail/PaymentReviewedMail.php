<?php

namespace App\Mail;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\SubscriptionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the shop owner an admin confirmed or rejected the payment they sent. */
class PaymentReviewedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SubscriptionPayment $payment)
    {
        $this->afterCommit();
    }

    public function confirmed(): bool
    {
        return $this->payment->status === SubscriptionPaymentStatus::Confirmed;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->confirmed()
            ? 'Payment confirmed — thank you'
            : 'We couldn’t confirm your payment');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-reviewed', with: [
            'confirmed' => $this->confirmed(),
            'purpose' => $this->payment->purpose(),
        ]);
    }
}
