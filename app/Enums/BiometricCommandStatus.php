<?php

namespace App\Enums;

/** A command waiting for a fingerprint device to collect it (ADMS getrequest) and confirm it (devicecmd). */
enum BiometricCommandStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting for the device',
            self::Sent => 'Sent to the device',
            self::Done => 'Done',
            self::Failed => 'Failed on the device',
        };
    }
}
