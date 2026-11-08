@props([
    'statuses',
    'status',
    'paidFrom' => '',
    'amountAlways' => false,
    'amountHelp' => null,
])

{{--
    Payment block for the counter forms that ask "Has the customer paid you?"
    (SIM Sale, NADRA, Bills, Services, Repairs). Sets the same paymentStatus and
    amountPaid properties the old select and field did.

    amount-always: Repairs shows Amount Paid for every status and sends it live;
    the others show it for Partial only, sent on save.
--}}
<div class="space-y-4">
    <x-ui.field label="Payment Status" name="paymentStatus" for="paymentStatus" help="Has the customer paid you?" required>
        <x-ui.segmented
            name="paymentStatus"
            label="Payment Status"
            wire:model.live="paymentStatus"
            :options="collect($statuses)->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()"
            class="max-w-sm"
        />
    </x-ui.field>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @if ($amountAlways)
            <x-ui.field label="Amount Paid" name="amountPaid" for="amountPaid" :help="$amountHelp">
                <x-ui.input wire:model.live="amountPaid" id="amountPaid" type="number" min="0" step="0.01" />
            </x-ui.field>
        @elseif ($status === 'partial')
            <x-ui.field label="Amount Paid" name="amountPaid" for="amountPaid" :help="$amountHelp" required>
                <x-ui.input wire:model="amountPaid" id="amountPaid" type="number" min="0" step="0.01" />
            </x-ui.field>
        @endif

        <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
    </div>
</div>
