@props(['preview' => null])

<div>
    <input
        type="file"
        accept="image/*"
        {{ $attributes->merge(['class' => 'block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100']) }}
    >
    @if ($preview)
        <img src="{{ $preview }}" class="mt-2 h-16 w-16 rounded-lg border border-slate-200 object-cover" alt="">
    @endif
</div>
