<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;

/** The first time payroll is on: warns once about salaries already logged by hand in Expenses. */
trait ShowsPayrollNotice
{
    public function dismissPayrollNotice(): void
    {
        Auth::user()->shop->forceFill(['payroll_notice_dismissed_at' => now()])->save();
    }

    protected function showPayrollNotice(): bool
    {
        $shop = Auth::user()->shop;

        return (bool) $shop?->payroll_enabled && $shop->payroll_notice_dismissed_at === null;
    }
}
