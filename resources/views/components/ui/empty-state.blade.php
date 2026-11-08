@props(['title', 'description' => null])

<div {{ $attributes->class(['flex flex-col items-center justify-center rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center']) }}>
    @isset($icon)
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-primary-soft text-primary">
            {{ $icon }}
        </div>
    @endisset

    <h3 class="text-base font-bold tracking-tight text-ink">{{ $title }}</h3>

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-faint">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-5">
            {{ $action }}
        </div>
    @endisset
</div>
