{{-- The notice for the one limit a screen is about. Read-only: it never
     blocks anything itself. `informational` (Sales) drops the actions,
     since that limit never blocks. --}}
@props(['limit', 'informational' => false])

@php
    $key = $limit instanceof \App\Enums\LimitKey ? $limit : \App\Enums\LimitKey::from($limit);
    $user = auth()->user();
    $notice = $user?->shop ? app(\App\Services\Billing\LimitNotices::class)->for($user->shop, $key, $informational) : null;

    if ($notice) {
        $meter = $notice['meter'];
        $month = $key->isMonthly() ? ' this month' : '';
        $message = match ($notice['state']) {
            'warning' => 'You’ve used '.number_format($meter['used']).' of '.number_format($meter['limit']).' '.$key->unit($meter['limit']).$month.'.',
            'full' => 'You’ve used all '.number_format($meter['limit']).' '.$key->unit($meter['limit']).$month.' on your plan.',
            'over' => 'You’re using '.number_format($meter['used']).' '.$key->unit($meter['used']).$month.' — your plan allows '.number_format($meter['limit']).'.',
        };
        $followUp = match (true) {
            $informational => $notice['state'] === 'warning' ? null : 'Sales keep working — the plan just needs upgrading.',
            $notice['state'] === 'warning' => null,
            default => 'You can’t add more until you add to your plan'.($key->isMonthly() ? ' or the count resets on the 1st' : '').'.',
        };
        $text = trim($message.' '.$followUp);
        $hasActions = ! $informational && (! $user->is_owner || $notice['pack'] || $notice['upgrade']);
    }
@endphp

@if ($notice)
    <x-ui.alert :variant="$notice['alarming'] ? 'danger' : 'warning'" :title="$notice['state'] === 'warning' ? 'Nearing your plan limit' : 'Plan limit reached'" role="status" {{ $attributes->merge(['class' => 'mb-4']) }} data-limit-notice="{{ $key->value }}">
        <p>{{ $text }}</p>

        @if ($hasActions)
            <x-slot:actions>
                @if ($user->is_owner)
                    @if ($notice['pack'])
                        <x-ui.button size="sm" variant="secondary" href="{{ route('billing.index') }}#addons">Buy add-on</x-ui.button>
                    @endif
                    @if ($notice['upgrade'])
                        <x-ui.button size="sm" href="{{ route('billing.index') }}#plans">Upgrade plan</x-ui.button>
                    @endif
                @else
                    <span class="font-medium">Ask the shop owner to upgrade.</span>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.alert>
@endif
