{{-- Shown until dismissed. --}}
<div class="mb-5 flex flex-col gap-3 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm sm:flex-row sm:items-start">
    <svg class="mt-0.5 hidden h-4 w-4 shrink-0 text-warning sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
    </svg>
    <div class="min-w-0 flex-1">
        <p class="font-semibold text-ink">Do you already log salaries in Expenses?</p>
        <p class="mt-0.5 text-ink-muted">Each payslip you mark Paid adds its own entry to Expenses. If you also keep adding salaries by hand, they'll count twice in your Net (Revenue − Expenses). From now on, pay salaries here and stop adding them by hand.</p>
    </div>
    <x-ui.button type="button" size="sm" variant="secondary" wire:click="dismissPayrollNotice" class="self-start">Got it</x-ui.button>
</div>
