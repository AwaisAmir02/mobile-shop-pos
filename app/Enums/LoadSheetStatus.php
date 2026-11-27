<?php

namespace App\Enums;

/** A van's load sheet (W7): open until every order on it is delivered or brought back. */
enum LoadSheetStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
}
