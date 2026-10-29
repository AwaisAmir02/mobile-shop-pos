<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Expired => 'Expired',
            self::Revoked => 'Disconnected',
            self::Error => 'Error',
        };
    }
}
