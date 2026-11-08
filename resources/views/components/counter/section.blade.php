@props(['title'])

{{-- One labelled part of an x-counter.form, divided from the next by a rule. --}}
<section {{ $attributes->class(['py-5 first:pt-0 last:pb-0']) }}>
    <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-ink-faint">{{ $title }}</h3>
    {{ $slot }}
</section>
