<?php

namespace App\Enums;

/** A booked order (W7). Only a booked one holds stock; delivery makes it a sale. */
enum SalesOrderStatus: string
{
    case Booked = 'booked';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
