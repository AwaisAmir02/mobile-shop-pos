@php
    $user = auth()->user();
    $alerts = $user?->is_owner && $user->shop && ! request()->routeIs('billing.index')
        ? collect(app(\App\Services\Billing\Entitlements::class)->meters($user->shop))->whereIn('state', ['warning', 'full'])
        : collect();
    $full = $alerts->where('state', 'full');
@endphp

@if ($alerts->isNotEmpty())
    <x-ui.alert flush :variant="$full->isNotEmpty() ? 'danger' : 'warning'" role="status">
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
