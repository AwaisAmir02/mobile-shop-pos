@props(['title' => null, 'description' => null, 'padding' => true])

<div {{ $attributes->class(['bg-white rounded-xl border border-slate-200 shadow-card']) }}>
    @if ($title || $description || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">
            <div>
                @if ($title)
                    <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div @class([$padding ? 'p-5' : ''])>
        {{ $slot }}
    </div>
</div>
