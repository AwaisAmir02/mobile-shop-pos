@props(['active'])

<x-ui.module-tabs module="payroll" :active="$active" label="Payroll sections" />

@unless (auth()->user()->shop?->payroll_enabled)
    <x-ui.alert variant="warning" class="mb-4">
        Payroll isn't on your plan right now. Your history is still here, but new payroll can't be run. Salaries already paid stay in your Expenses.
    </x-ui.alert>
@endunless
