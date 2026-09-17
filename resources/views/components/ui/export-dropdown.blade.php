@props(['pdfAction' => 'exportPdf', 'excelAction' => 'exportExcel'])

<div x-data="{ open: false }" class="relative shrink-0">
    <x-ui.button type="button" variant="secondary" size="sm" x-on:click="open = ! open" x-on:click.outside="open = false">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
        </svg>
        Export
    </x-ui.button>

    <div
        x-show="open"
        x-cloak
        x-transition
        class="absolute right-0 z-40 mt-2 w-40 rounded-xl border border-slate-200 bg-white py-1.5 shadow-card"
    >
        <button
            type="button"
            wire:click="{{ $pdfAction }}"
            x-on:click="open = false"
            class="block w-full px-4 py-2 text-left text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
        >
            Export as PDF
        </button>
        <button
            type="button"
            wire:click="{{ $excelAction }}"
            x-on:click="open = false"
            class="block w-full px-4 py-2 text-left text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
        >
            Export as Excel
        </button>
    </div>
</div>
