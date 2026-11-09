{{-- An explanatory note: a sentence or two a screen needs to explain its figures
     (Accounts, Payroll), set apart as a quiet note rather than body text. --}}
<div {{ $attributes->class(['mb-5 flex max-w-3xl gap-3 rounded-xl border border-info/15 bg-info-soft px-4 py-3 text-sm text-ink-muted']) }}>
    <svg class="mt-0.5 h-4 w-4 shrink-0 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
    </svg>
    <p>{{ $slot }}</p>
</div>
