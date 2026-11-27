<?php

namespace App\Enums;

/** A claim on the company for scheme free goods (W9). */
enum SchemeClaimStatus: string
{
    case Submitted = 'submitted';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
