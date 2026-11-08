{{-- A screen's totals as one row of x-ui.stat cards: two across on a phone, up to
     four across on a laptop, never squeezed beside the filters. --}}
<div {{ $attributes->class(['mb-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4']) }}>
    {{ $slot }}
</div>
