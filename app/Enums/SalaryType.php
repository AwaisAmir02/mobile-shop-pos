<?php

namespace App\Enums;

enum SalaryType: string
{
    case Fixed = 'fixed';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed monthly',
            self::Daily => 'Daily wage',
        };
    }

    public function rateLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Monthly Salary',
            self::Daily => 'Daily Rate',
        };
    }
}
