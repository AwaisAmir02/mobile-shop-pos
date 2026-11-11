@php
    $user = auth()->user();
    $show = $user?->is_owner && $user->shop_id && ! request()->routeIs('billing.index');
    $openPayment = $show ? \App\Models\SubscriptionPayment::openFor($user->shop_id) : null;
    $cycle = app(\App\Services\Billing\BillingCycle::class);
    $phase = $show ? $cycle->phase($user->shop) : null;
    $daysLeft = $phase ? $cycle->daysLeft($user->shop) : 0;
    $endsAt = $phase ? $cycle->endsAt($user->shop) : null;
    $days = $daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft).' left';
    // A payment that's sent or rejected says so first; otherwise the trial or renewal countdown.
    $countdown = in_array($phase, [\App\Enums\BillingPhase::Trial, \App\Enums\BillingPhase::EndingSoon], true)
        && ! $openPayment?->submitted_at;
@endphp

@if ($countdown)
    <x-ui.alert flush :variant="$phase === \App\Enums\BillingPhase::Trial && $daysLeft > 3 ? 'info' : 'warning'" role="status" data-billing-countdown>
        <p>
            @if ($phase === \App\Enums\BillingPhase::Trial)
                <span class="font-semibold">Free trial: {{ $days }}.</span> Your {{ $user->shop->plan?->name }} plan is free until {{ $endsAt->format('d M Y') }}. Pay any time before then to keep using your shop without a break.
            @else
                <span class="font-semibold">Your subscription is ending soon: {{ $days }}.</span> Your paid period ends {{ $endsAt->copy()->subDay()->format('d M Y') }}. Pay now so nothing stops.
            @endif
        </p>
        <x-slot:actions>
            <a href="{{ route('billing.index') }}" wire:navigate class="font-semibold underline underline-offset-2 hover:no-underline">Pay now</a>
        </x-slot:actions>
    </x-ui.alert>
@elseif ($openPayment)
    @php($rejected = $openPayment->status === \App\Enums\SubscriptionPaymentStatus::Rejected)
    <x-ui.alert flush :variant="$rejected ? 'danger' : 'warning'" role="status">
        <p>
            @if ($rejected)
                <span class="font-semibold">Payment not confirmed.</span> Please check the details and submit again.
            @elseif ($openPayment->isAwaitingReview())
                <span class="font-semibold">Payment received — we’re confirming it.</span> Verification can take up to a day. Your subscription activates once it’s verified.
            @else
                <span class="font-semibold">Payment pending.</span> Pay Rs {{ number_format((float) $openPayment->amount) }} by bank transfer to activate {{ $openPayment->shopAddon ? 'your '.$openPayment->shopAddon->name.' add-on' : 'your subscription' }}.
            @endif
        </p>
        <x-slot:actions>
            <a href="{{ route('billing.index') }}" wire:navigate class="font-semibold underline underline-offset-2 hover:no-underline">{{ $openPayment->isAwaitingReview() ? 'View details' : 'Pay now' }}</a>
        </x-slot:actions>
    </x-ui.alert>
@endif
