<?php

namespace App\Support;

class PhoneNumber
{
    public const DEFAULT_COUNTRY_CODE = '92';

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = self::DEFAULT_COUNTRY_CODE.substr($digits, 1);
        } elseif (str_starts_with($digits, '3') && strlen($digits) === 10) {
            $digits = self::DEFAULT_COUNTRY_CODE.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    public static function isPakistaniMobile(?string $raw): bool
    {
        return (bool) preg_match('/^923\d{9}$/', (string) self::normalize($raw));
    }

    public static function display(string $normalized): string
    {
        return preg_match('/^92(3\d{2})(\d{7})$/', $normalized, $m) ? '+92 '.$m[1].' '.$m[2] : '+'.$normalized;
    }
}
