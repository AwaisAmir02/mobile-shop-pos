@php
    $cycle = app(\App\Services\Billing\BillingCycle::class);
    $shop = auth()->user()->shop;
    $phase = $cycle->phase($shop);
    $owner = $shop->user;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-ui.page-header>Payment needed</x-ui.page-header>
    </x-slot>

    <div class="mx-auto max-w-lg" data-subscription-locked>
        <x-ui.alert variant="danger" :title="$phase === \App\Enums\BillingPhase::TrialEnded ? 'Your shop’s free trial has ended' : 'Your shop’s subscription has ended'">
            <p>Every screen is paused until the payment is made and we’ve verified it. Nothing has been deleted — all your shop’s records are safe.</p>
            <p class="mt-2">Please ask {{ $owner?->name ?? 'the shop owner' }} to log in and complete the payment from <span class="font-semibold">Plan &amp; Usage</span>. Verification can take up to a day.</p>
        </x-ui.alert>
    </div>
</x-app-layout>
