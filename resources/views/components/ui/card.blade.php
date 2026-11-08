@props(['title' => null, 'description' => null, 'padding' => true])

<div {{ $attributes->class(['bg-surface rounded-2xl border border-line shadow-soft']) }}>
    @if ($title || $description || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-line px-4 py-3.5 sm:px-5">
            <div>
                @if ($title)
                    <h3 class="text-base font-bold tracking-tight text-ink">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-ink-faint">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div @class([$padding ? 'p-4 sm:p-5' : ''])>
        {{ $slot }}
    </div>
</div>
