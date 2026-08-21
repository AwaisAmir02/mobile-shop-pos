@props(['label' => null, 'name' => null, 'for' => null, 'required' => false, 'help' => null])

<div {{ $attributes->only('class') }}>
    @if ($label)
        <x-ui.label :for="$for ?? $name" :value="$label" :required="$required" />
    @endif

    <div @if ($label) class="mt-1.5" @endif>
        {{ $slot }}
    </div>

    @if ($help && ! ($name && $errors->has($name)))
        <p class="mt-1.5 text-sm text-slate-500">{{ $help }}</p>
    @endif

    @if ($name)
        <x-ui.error :messages="$errors->get($name)" class="mt-1.5" />
    @endif
</div>
