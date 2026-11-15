<?php

namespace App\Couriers;

use RuntimeException;

/** A courier call failed. The message is shown to the shop as it is. */
class CourierException extends RuntimeException
{
}
