@props(['value' => '', 'label' => 'Paid From', 'blank' => 'Not recorded'])

@if (auth()->user()->shop?->ledger_enabled)
    <div wire:key="paid-from-{{ $value }}" {{ $attributes->only('class') }}>
        <x-ui.field :label="$label" name="paidFrom" for="paidFrom" help="For your ledger">
            <x-ui.select wire:model="paidFrom" id="paidFrom">
                <option value="">{{ $blank }}</option>
                <option value="cash">Cash</option>
                @foreach (\App\Models\ShopAccount::query()->orderBy('name')->get() as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </div>
@endif
