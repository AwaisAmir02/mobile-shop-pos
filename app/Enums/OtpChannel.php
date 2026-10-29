<?php

namespace App\Enums;

enum OtpChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
        };
    }
}
