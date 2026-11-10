<?php

namespace App\Casts;

use App\Support\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A DECIMAL(12,3) quantity column: whole values read as plain integers, so a
 * piece-only product shows 5 rather than "5.000". Direct SQL (SUM, value())
 * bypasses this, so those callers go through Quantity::normalize() themselves.
 */
class QuantityCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : Quantity::normalize($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        // Anything else is left for the database to refuse.
        return is_numeric($value) ? number_format((float) Quantity::normalize($value), Quantity::DECIMALS, '.', '') : $value;
    }
}
