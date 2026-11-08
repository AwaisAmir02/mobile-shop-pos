{{--
    The shared layout for the counter-service forms (SIM Sale, Balance Load,
    Wallet Load, Bills, NADRA, Repairs, Services): the entry on the left as
    x-counter.section parts inside one card, and the total with Save kept in view
    on the right. On phones the summary follows the form.

    The whole layout is one <form>, so Enter in any field and the summary's Save
    button both submit, as they did before.
--}}
<form {{ $attributes->class(['grid grid-cols-1 gap-6 lg:grid-cols-3']) }}>
    <x-ui.card class="min-w-0 lg:col-span-2">
        <div class="divide-y divide-line">
            {{ $slot }}
        </div>
    </x-ui.card>

    <div class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        {{ $summary }}
    </div>
</form>
