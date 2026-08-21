<?php

namespace App\Enums;

enum SimForm: string
{
    case Physical = 'physical';
    case Esim = 'esim';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'Physical SIM',
            self::Esim => 'eSIM',
        };
    }
}
