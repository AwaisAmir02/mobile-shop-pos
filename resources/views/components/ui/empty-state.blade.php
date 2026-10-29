@props(['title', 'description' => null])

<div {{ $attributes->class(['flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 px-6 py-10 text-center']) }}>
    @isset($icon)
        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 text-brand-600">
            {{ $icon }}
        </div>
    @endisset

    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endisset
</div>
