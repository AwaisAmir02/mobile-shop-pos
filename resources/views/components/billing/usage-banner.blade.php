@php
    $user = auth()->user();
    $alerts = $user?->is_owner && $user->shop && ! request()->routeIs('billing.index')
        ? collect(app(\App\Services\Billing\Entitlements::class)->meters($user->shop))->whereIn('state', ['warning', 'full'])
            // A limit this page already explains in its own notice isn't repeated here.
            ->whereNotIn('key', app(\App\Services\Billing\LimitNotices::class)->shown())
        : collect();
    $full = $alerts->where('state', 'full');
    // Red only when a limit that alarms is full; a shop that has filled the
    // branches or staff seats it paid for gets the amber treatment instead.
    $alarming = $full->contains(fn ($m) => $m['alarm'] ?? true);
@endphp

@if ($alerts->isNotEmpty())
    <x-ui.alert flush :variant="$alarming ? 'danger' : 'warning'" role="status">
        <p>
            @if ($full->isNotEmpty())
                <span class="font-semibold">Plan limit reached:</span> {{ $full->map(fn ($m) => $m['label'].' ('.number_format($m['used']).'/'.number_format($m['limit']).')')->implode(', ') }}.
            @else
                <span class="font-semibold">Nearing your plan limit:</span> {{ $alerts->map(fn ($m) => $m['label'].' ('.number_format($m['used']).'/'.number_format($m['limit']).')')->implode(', ') }}.
            @endif
        </p>
        <x-slot:actions>
            <a href="{{ route('billing.index') }}" wire:navigate class="font-semibold underline underline-offset-2 hover:no-underline">View usage</a>
        </x-slot:actions>
    </x-ui.alert>
@endif
