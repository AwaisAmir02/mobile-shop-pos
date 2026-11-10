@props(['value' => 'cash', 'label' => 'Paid From', 'blank' => 'Not recorded', 'help' => 'Keeps your cash and account balances right'])

{{-- Where money went or came from: cash, one of the shop's accounts, or not recorded.
     Every shop sees it; Cash & Balances (and the ledger, where it's on) read it. --}}
<div wire:key="paid-from-{{ $value }}" {{ $attributes->only('class') }}>
    <x-ui.field :label="$label" name="paidFrom" for="paidFrom" :help="$help">
        <x-ui.select wire:model="paidFrom" id="paidFrom">
            <option value="">{{ $blank }}</option>
            <option value="cash">Cash</option>
            @foreach (\App\Models\ShopAccount::query()->orderBy('name')->get() as $account)
                <option value="{{ $account->id }}">{{ $account->name }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
</div>
