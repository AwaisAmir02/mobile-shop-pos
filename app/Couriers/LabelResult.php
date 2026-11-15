<?php

namespace App\Couriers;

/** A label is either a link the browser can open, or PDF bytes we serve. */
final class LabelResult
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $pdf = null,
    ) {
    }
}
