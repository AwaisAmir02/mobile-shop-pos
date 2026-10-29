@props(['eyebrow' => null, 'title', 'lead' => null, 'align' => 'center', 'dark' => false])

<div {{ $attributes->class(['max-w-3xl', 'mx-auto text-center' => $align === 'center']) }} data-reveal>
    @if ($eyebrow)
        <p @class(['text-sm font-semibold uppercase tracking-[0.18em]', 'text-accent-300' => $dark, 'text-accent-600' => ! $dark])>{{ $eyebrow }}</p>
    @endif
    <h2 @class(['mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl lg:text-[2.75rem] lg:leading-[1.1]', 'text-white' => $dark, 'text-slate-900' => ! $dark])>{{ $title }}</h2>
    @if ($lead)
        <p @class(['mt-5 text-lg leading-8', 'text-brand-100' => $dark, 'text-slate-600' => ! $dark])>{{ $lead }}</p>
    @endif
</div>
