@php
    $user = auth()->user();
    $openPayment = $user?->is_owner && $user->shop_id && ! request()->routeIs('billing.index')
        ? \App\Models\SubscriptionPayment::openFor($user->shop_id)
        : null;
@endphp

@if ($openPayment)
    @php($rejected = $openPayment->status === \App\Enums\SubscriptionPaymentStatus::Rejected)
    <x-ui.alert flush :variant="$rejected ? 'danger' : 'warning'" role="status">
        <p>
            @if ($rejected)
                <span class="font-semibold">Payment not confirmed.</span> Please check the details and submit again.
            @elseif ($openPayment->isAwaitingReview())
                <span class="font-semibold">Payment received — we’re confirming it.</span> Your subscription activates once it’s verified.
            @else
                <span class="font-semibold">Payment pending.</span> Pay Rs {{ number_format((float) $openPayment->amount) }} by bank transfer to activate {{ $openPayment->shopAddon ? 'your '.$openPayment->shopAddon->name.' add-on' : 'your subscription' }}.
            @endif
        </p>
        <x-slot:actions>
            <a href="{{ route('billing.index') }}" wire:navigate class="font-semibold underline underline-offset-2 hover:no-underline">{{ $openPayment->isAwaitingReview() ? 'View details' : 'Pay now' }}</a>
        </x-slot:actions>
    </x-ui.alert>
@endif
