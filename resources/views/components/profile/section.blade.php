@props([
    'title',
    'description' => null,
    // A destructive section (Delete Account) is set apart in red.
    'danger' => false,
])

{{-- One part of the user's own account page: what it is on the left, the form
     on the right. Used by /profile and the Settings "Profile" tab alike. --}}
<section {{ $attributes->class(['grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-8']) }}>
    <div class="lg:pt-5">
        <h2 @class(['text-base font-bold tracking-tight', 'text-danger' => $danger, 'text-ink' => ! $danger])>{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-ink-faint">{{ $description }}</p>
        @endif
    </div>

    {{-- The card drawn here rather than with x-ui.card: a second border colour on
         x-ui.card would lose to its own (CSS order), so the danger tint is set once. --}}
    <div @class([
        'rounded-2xl border bg-surface p-4 shadow-soft sm:p-5 lg:col-span-2',
        'border-danger/25' => $danger,
        'border-line' => ! $danger,
    ])>
        {{ $slot }}
    </div>
</section>
