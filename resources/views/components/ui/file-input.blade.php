@props(['preview' => null])

<div>
    <input
        type="file"
        accept="image/*"
        {{ $attributes->merge(['class' => 'block w-full text-sm text-ink-faint file:mr-4 file:rounded-full file:border-0 file:bg-primary-soft file:px-4 file:py-2 file:text-sm file:font-semibold file:text-on-primary-soft hover:file:bg-primary-soft/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-link']) }}
    >
    @if ($preview)
        <img src="{{ $preview }}" class="mt-2 h-16 w-16 rounded-xl border border-line object-cover" alt="">
    @endif
</div>
