@props([
    // The screen's existing date properties; they keep their names and ids.
    'from' => 'from',
    'to' => 'to',
])

{{-- Quick ranges plus the two date boxes, all writing the same from/to
     properties the screen already filters on. --}}
<div x-data="dateRange(@js($from), @js($to))" class="contents">
    <x-ui.field label="Dates" for="{{ $from }}-preset" class="w-full sm:w-40">
        <select
            id="{{ $from }}-preset"
            x-on:change="choose($event.target.value)"
            :value="preset"
            class="block w-full rounded-lg border-line-strong bg-surface py-2 pl-3 pr-9 text-base text-ink shadow-soft focus:border-link focus:ring-link sm:text-sm"
        >
            <option value="all" :selected="preset === 'all'">Any time</option>
            <option value="today" :selected="preset === 'today'">Today</option>
            <option value="yesterday" :selected="preset === 'yesterday'">Yesterday</option>
            <option value="week" :selected="preset === 'week'">Last 7 days</option>
            <option value="month" :selected="preset === 'month'">This month</option>
            <option value="lastMonth" :selected="preset === 'lastMonth'">Last month</option>
            <option value="custom" :selected="preset === 'custom'" disabled>Custom range</option>
        </select>
    </x-ui.field>

    <x-ui.field label="From" :name="$from" :for="$from" class="w-[calc(50%-0.375rem)] sm:w-40">
        <x-ui.input wire:model.live="{{ $from }}" id="{{ $from }}" type="date" />
    </x-ui.field>

    <x-ui.field label="To" :name="$to" :for="$to" class="w-[calc(50%-0.375rem)] sm:w-40">
        <x-ui.input wire:model.live="{{ $to }}" id="{{ $to }}" type="date" />
    </x-ui.field>
</div>
