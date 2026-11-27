<?php

namespace App\Enums;

/**
 * The price level a wholesale customer buys at (W3, PriceTiers). On a
 * customer, null means the default, Trade; a sale line or order line records
 * Trade when it was priced at the trade price, and null when at the normal
 * selling price.
 */
enum PriceTier: string
{
    case Trade = 'trade';
    case Normal = 'normal';
}
