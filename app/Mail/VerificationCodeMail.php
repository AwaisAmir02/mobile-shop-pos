<?php

namespace App\Mail;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerificationCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public OtpPurpose $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->code.' is your '.config('branding.name').' verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verification-code', with: [
            'minutes' => config('verification.code_ttl_minutes'),
            'action' => $this->purpose === OtpPurpose::PasswordReset ? 'reset your password' : 'create your shop',
        ]);
    }
}
