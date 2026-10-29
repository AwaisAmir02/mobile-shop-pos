<?php

namespace App\Exceptions;

use App\Enums\LimitKey;
use RuntimeException;

class UsageLimitReached extends RuntimeException
{
    public function __construct(public readonly LimitKey $key, public readonly int $limit)
    {
        parent::__construct('Your plan allows '.number_format($limit).' '.$key->unit().($key->isMonthly() ? ' a month' : '').'. Upgrade your plan or buy an add-on in Plan & Billing to add more.');
    }
}
