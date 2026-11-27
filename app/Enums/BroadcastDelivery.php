<?php

namespace App\Enums;

/** How one person's copy of a broadcast went out. */
enum BroadcastDelivery: string
{
    /** Sent through the shop's WhatsApp Business number (they messaged in the last 24 hours). */
    case Sent = 'sent';
    case Failed = 'failed';
    /** Outside WhatsApp's 24-hour window: staff send it from their own phone with a link. */
    case ByHand = 'by_hand';
    /** Staff opened the link for this person. */
    case OpenedByHand = 'opened';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::ByHand => 'To send by hand',
            self::OpenedByHand => 'Opened to send',
        };
    }
}
