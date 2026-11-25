@props([
    // The register's creditStatus(): customer, unpaid, check (CreditControl::check), terms, defaultDue, isOwner
    'status',
    'dueDate' => '',
])

{{-- Wholesale businesses only: the customer's credit position with this sale,
     the due date a credit sale gets, and the owner's approval to go over the
     limit. Binds dueDate, overrideCredit, overrideReason and ownerPassword. --}}
@php
    $check = $status['check'];
    $customer = $status['customer'];
@endphp

<div class="space-y-3 rounded-xl border border-line p-3 text-sm" data-wholesale-credit>
    @if ($check !== null)
        <div class="flex items-center justify-between gap-3">
            <span class="text-ink-faint">Credit used</span>
            <span class="tabular-nums font-semibold text-ink">Rs {{ number_format($check['owed'], 2) }} of Rs {{ number_format($check['limit'], 2) }}</span>
        </div>
    @endif
    @if ($status['terms'])
        <p class="text-xs text-ink-faint">{{ $status['terms'] }}-day payment terms</p>
    @endif

    @if ($status['unpaid'] > 0)
        <x-ui.field label="Due date" name="dueDate" for="dueDate" :help="$status['defaultDue'] ? 'Blank: '.\Carbon\Carbon::parse($status['defaultDue'])->format('d M Y').', from the payment terms' : 'Optional. When this bill should be paid'">
            <x-ui.input wire:model="dueDate" id="dueDate" type="date" />
        </x-ui.field>
    @endif

    @if ($check !== null && $check['over'])
        <div class="rounded-lg bg-danger-soft px-3 py-2 text-danger" data-credit-over>
            <p class="font-semibold">This sale goes over the credit limit</p>
            <p class="mt-0.5 text-xs">{{ $customer->name }} would owe Rs {{ number_format($check['after'], 2) }} against a limit of Rs {{ number_format($check['limit'], 2) }}. Take more payment now, or have the owner approve it.</p>
        </div>
        <x-ui.checkbox wire:model.live="overrideCredit" label="Owner approves going over the limit" />
        <div x-show="$wire.overrideCredit" x-cloak class="space-y-3">
            <x-ui.field label="Reason" name="overrideReason" for="overrideReason">
                <x-ui.input wire:model="overrideReason" id="overrideReason" maxlength="255" placeholder="e.g. Regular customer, pays monthly" />
            </x-ui.field>
            @unless ($status['isOwner'])
                <x-ui.field label="Owner's password" name="ownerPassword" for="ownerPassword" help="The owner types it here to approve">
                    <x-ui.input wire:model="ownerPassword" id="ownerPassword" type="password" autocomplete="off" />
                </x-ui.field>
            @endunless
        </div>
    @elseif ($check !== null)
        <p class="text-xs text-ink-faint">Rs {{ number_format($check['available'], 2) }} of credit left before this sale.</p>
    @endif
    @error('credit')
        <p class="text-xs font-semibold text-danger">{{ $message }}</p>
    @enderror
</div>
