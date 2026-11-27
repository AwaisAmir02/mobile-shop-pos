<?php

namespace App\Enums;

/** A cheque the shop took from a customer, or gave to a vendor (W6). */
enum ChequeDirection: string
{
    case Received = 'received';
    case Issued = 'issued';
}
