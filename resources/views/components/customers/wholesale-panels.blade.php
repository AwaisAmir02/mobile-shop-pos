@props([
    'customer',
    // DuesAging::forCustomer(): bills and udhaar by age
    'aging',
    // CreditControl::check($customer, 0), or null without a limit
    'creditCheck',
    // What they buy at: the trade price's name, or "Normal selling price"
    'tierLabel',
    // Their latest CustomerReceipts
    'receipts',
    'canReceive' => false,
    // CustomerPayments::allocate() for the amount being received: where it goes
    'receiptPlan' => [],
    // Received cheques not yet cleared, or null without the cheques tool
    'chequesInHand' => null,
    'canContra' => false,
    // Contra::position(), when they are also a vendor
    'contra' => null,
    'contraSettlements' => [],
    'canBookOrder' => false,
    // Everything they owe, as the Udhaar list shows it
    'totalOwed' => 0.0,
    // The Received Into choice (ChoosesPaymentSource)
    'paidFrom' => 'cash',
])

{{-- customers.show, wholesale businesses only: business details, credit,
     dues aging, cheques, contra, Receive Payment and recent receipts. Its
     inputs and actions (wire:) belong to the customer page. --}}
<div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3" data-wholesale-panels>
    <x-ui.card title="Business" class="lg:col-span-1">
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Business</dt><dd class="text-right font-semibold text-ink">{{ $customer->business_name ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Contact</dt><dd class="text-right text-ink">{{ $customer->contact_person ?: $customer->name }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Phones</dt><dd class="text-right text-ink">{{ collect([$customer->phone, $customer->phone_2])->filter()->implode(' · ') ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Area / route</dt><dd class="text-right text-ink">{{ collect([$customer->area, $customer->deliveryRoute?->name])->filter()->implode(' · ') ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Tax numbers</dt><dd class="text-right text-ink">{{ $customer->taxNumbersLine() ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Sells at</dt><dd class="text-right text-ink">{{ $tierLabel }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-ink-faint">Change these with Edit on the Customers list.</p>
    </x-ui.card>

    <x-ui.card title="Credit" class="lg:col-span-1" data-credit-card>
        <x-slot name="actions">
            @if ($canReceive)
                <x-ui.button type="button" size="sm" wire:click="openReceive" data-receive-payment>Receive Payment</x-ui.button>
            @endif
        </x-slot>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Credit limit</dt><dd class="text-right font-semibold tabular-nums text-ink">{{ $creditCheck ? 'Rs '.number_format($creditCheck['limit'], 2) : 'No limit' }}</dd></div>
            @if ($creditCheck)
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Used</dt><dd class="text-right tabular-nums text-ink">Rs {{ number_format($creditCheck['owed'], 2) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-faint">Available</dt><dd @class(['text-right tabular-nums font-semibold', 'text-danger' => $creditCheck['owed'] > $creditCheck['limit'], 'text-success' => $creditCheck['owed'] <= $creditCheck['limit']])>{{ $creditCheck['owed'] > $creditCheck['limit'] ? 'Over by Rs '.number_format($creditCheck['owed'] - $creditCheck['limit'], 2) : 'Rs '.number_format($creditCheck['available'], 2) }}</dd></div>
            @endif
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Payment terms</dt><dd class="text-right text-ink">{{ $customer->payment_terms_days ? $customer->payment_terms_days.' days' : 'None' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-faint">Overdue bills</dt><dd @class(['text-right tabular-nums', 'font-semibold text-danger' => $aging['bills']['overdue'] > 0, 'text-ink' => $aging['bills']['overdue'] <= 0])>Rs {{ number_format($aging['bills']['overdue'], 2) }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card title="How long it's owed" class="lg:col-span-1" data-customer-aging>
        @if ($aging['total'] <= 0)
            <p class="text-sm text-ink-faint">Nothing owed right now.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-ink-faint"><th class="pb-1 text-left font-medium"></th><th class="pb-1 text-right font-medium">Bills</th><th class="pb-1 text-right font-medium">Udhaar</th></tr>
                </thead>
                <tbody class="tabular-nums">
                    @foreach (\App\ReportQueries\DuesAging::BUCKETS as $i => $bucket)
                        <tr>
                            <td class="py-0.5 text-ink-faint">{{ $bucket }}</td>
                            <td @class(['py-0.5 text-right', 'font-semibold text-danger' => $i === 3 && $aging['bills']['buckets'][$i] > 0, 'text-ink' => ! ($i === 3 && $aging['bills']['buckets'][$i] > 0)])>{{ $aging['bills']['buckets'][$i] > 0 ? number_format($aging['bills']['buckets'][$i], 2) : '—' }}</td>
                            <td @class(['py-0.5 text-right', 'font-semibold text-danger' => $i === 3 && $aging['udhaar']['buckets'][$i] > 0, 'text-ink' => ! ($i === 3 && $aging['udhaar']['buckets'][$i] > 0)])>{{ $aging['udhaar']['buckets'][$i] > 0 ? number_format($aging['udhaar']['buckets'][$i], 2) : '—' }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-line font-semibold text-ink">
                        <td class="pt-1">Total</td>
                        <td class="pt-1 text-right">{{ number_format($aging['bills']['total'], 2) }}</td>
                        <td class="pt-1 text-right">{{ number_format($aging['udhaar']['total'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-xs text-ink-faint">Bills and udhaar loans are kept apart.</p>
        @endif
    </x-ui.card>
</div>

@if ($chequesInHand !== null || $canContra || $canBookOrder)
    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        @if ($chequesInHand !== null)
            <x-ui.card title="Cheques in hand" description="Not paid off until they clear" data-customer-cheques>
                <x-slot name="actions">
                    <x-ui.button size="sm" variant="secondary" href="{{ route('cheques.index', ['customer' => $customer->id]) }}" wire:navigate>Record Cheque</x-ui.button>
                </x-slot>
                @if ($chequesInHand->isEmpty())
                    <p class="text-sm text-ink-faint">None.</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($chequesInHand as $cheque)
                            <li class="flex justify-between gap-3 py-2"><span>{{ $cheque->label() }} · {{ $cheque->cheque_date->format('d M Y') }}</span><span class="font-semibold tabular-nums text-ink">Rs {{ number_format($cheque->amount, 2) }}</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-ink-faint">Rs {{ number_format($chequesInHand->sum('amount'), 2) }} in all.</p>
                @endif
            </x-ui.card>
        @endif

        @if ($canContra)
            <x-ui.card title="Contra with {{ $customer->vendor?->name }}" description="They are also your vendor. Set their bills off against what you owe them; no money moves, udhaar is left alone." data-contra>
                <x-slot name="actions">
                    @if ($contra['most'] > 0)
                        <x-ui.button size="sm" wire:click="openContra" data-open-contra>Set Off</x-ui.button>
                    @endif
                </x-slot>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">They owe you (bills)</dt><dd class="tabular-nums text-ink">Rs {{ number_format($contra['customer_owes'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">You owe them (stock)</dt><dd class="tabular-nums text-ink">Rs {{ number_format($contra['vendor_owed'], 2) }}</dd></div>
                    <div class="flex justify-between font-semibold"><dt class="text-ink">Can be set off</dt><dd class="tabular-nums text-ink">Rs {{ number_format($contra['most'], 2) }}</dd></div>
                </dl>
                @if ($contraSettlements->isNotEmpty())
                    <ul class="mt-4 divide-y divide-line border-t border-line text-sm">
                        @foreach ($contraSettlements as $settlement)
                            <li wire:key="contra-{{ $settlement->id }}" @class(['flex items-center justify-between gap-3 py-2', 'opacity-60' => $settlement->isCancelled()])>
                                <span>{{ $settlement->number() }} · {{ $settlement->settled_on->format('d M Y') }}{{ $settlement->isCancelled() ? ' · undone' : '' }}</span>
                                <span class="flex items-center gap-3">
                                    <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($settlement->amount, 2) }}</span>
                                    @unless ($settlement->isCancelled())
                                        <button type="button" wire:click="cancelContra({{ $settlement->id }})" wire:confirm="Undo {{ $settlement->number() }}? Both sides will owe again what it set off." class="text-xs font-semibold text-danger hover:underline">Undo</button>
                                    @endunless
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif

        @if ($canBookOrder)
            <x-ui.card title="Orders">
                <x-ui.button href="{{ route('sales-orders.create', ['customer' => $customer->id]) }}" wire:navigate data-book-for-customer>Book Order</x-ui.button>
            </x-ui.card>
        @endif
    </div>

    @if ($canContra)
        <x-ui.modal name="contra-form" max-width="sm">
            <form wire:submit="settleContra" class="p-6">
                <h2 class="text-lg font-bold text-ink">Set Off (Contra)</h2>
                <p class="mt-1 text-sm text-ink-muted">Pays their oldest bills and your oldest unpaid stock from them, by the same amount.</p>
                <div class="mt-4 space-y-4">
                    <x-ui.field label="Amount (Rs)" name="contraAmount" for="contraAmount" :help="'Up to Rs '.number_format($contra['most'], 2)">
                        <x-ui.input wire:model="contraAmount" id="contraAmount" type="number" min="0.01" step="0.01" />
                    </x-ui.field>
                    <x-ui.field label="Note" name="contraNote" for="contraNote">
                        <x-ui.input wire:model="contraNote" id="contraNote" maxlength="255" />
                    </x-ui.field>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'contra-form' })">Cancel</x-ui.button>
                    <x-ui.button type="submit" data-confirm-contra>Set Off</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endif

@if ($receipts->isNotEmpty())
    <x-ui.card title="Payments received" description="Lump sums taken with Receive Payment, and what each one paid." class="mb-6" data-receipts>
        <ul class="divide-y divide-line text-sm">
            @foreach ($receipts as $receipt)
                <li wire:key="receipt-{{ $receipt->id }}" class="flex items-start justify-between gap-3 py-2">
                    <span class="min-w-0">
                        <span class="font-medium text-ink">{{ $receipt->number() }}{{ $receipt->reversed_at ? ' · bounced, reversed' : '' }}</span>
                        <span class="block text-xs text-ink-faint">
                            {{ $receipt->receipt_date->format('d M Y') }} · {{ $receipt->receivedIntoLabel() }}
                            · {{ collect($receipt->applied_to)->map(fn ($part) => $part['source'] === 'sale' ? $part['reference'] : $part['label'])->implode(', ') }}
                            @if ($receipt->note)
                                · {{ $receipt->note }}
                            @endif
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($receipt->amount, 2) }}</span>
                        <button type="button" wire:click="downloadReceipt({{ $receipt->id }})" class="text-xs font-semibold text-link hover:underline">PDF</button>
                    </span>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
@endif

@if ($canReceive)
    <x-ui.modal name="receive-payment-form" max-width="md">
        <form wire:submit="receive" class="p-6">
            <h2 class="text-lg font-bold text-ink">Receive Payment</h2>
            <p class="mt-2 text-sm text-ink-muted">One amount from {{ $customer->displayName() }}. It pays their udhaar loan first, then each unpaid bill, oldest first. Anything more is kept as advance.</p>
            <div class="mt-5 space-y-4">
                <x-ui.field label="Amount (Rs)" name="receiptAmount" for="receiptAmount" :help="'They owe Rs '.number_format(max(0, $totalOwed), 2)">
                    <x-ui.input wire:model.live.debounce.400ms="receiptAmount" id="receiptAmount" type="number" min="1" step="0.01" />
                </x-ui.field>
                <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
                <x-ui.field label="Note" name="receiptNote" for="receiptNote" help="Optional, e.g. cheque number or who brought it">
                    <x-ui.input wire:model="receiptNote" id="receiptNote" maxlength="200" />
                </x-ui.field>

                @if ($receiptPlan !== [])
                    <div class="rounded-xl border border-line p-3" data-receipt-plan>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">Where it goes</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($receiptPlan as $part)
                                <li class="flex justify-between gap-3">
                                    <span class="min-w-0 truncate text-ink">{{ $part['source'] === 'sale' ? $part['reference'].' · '.$part['label'] : $part['label'] }}</span>
                                    <span class="shrink-0 tabular-nums font-semibold text-ink">Rs {{ number_format($part['amount'], 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeReceive">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="receive">Save Receipt</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endif
