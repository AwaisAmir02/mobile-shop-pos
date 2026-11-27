@props(['group' => '', 'category' => '', 'addons' => []])

@php($chosenGroup = \App\Enums\BusinessGroup::tryFrom($group))

{{-- Two steps: the kind of business, then the type inside it. Bound to businessGroup / businessCategory (see PicksBusinessType). --}}
<div class="space-y-4">
    <fieldset>
        <legend class="block text-sm font-medium text-ink-muted">What kind of business are you?</legend>
        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach (\App\Enums\BusinessGroup::cases() as $option)
                <label wire:key="group-{{ $option->value }}" data-business-group="{{ $option->value }}" @class([
                    'flex cursor-pointer flex-col rounded-2xl border p-3.5 transition',
                    'border-link bg-primary-soft ring-2 ring-link' => $chosenGroup === $option,
                    'border-line hover:border-line-strong' => $chosenGroup !== $option,
                ])>
                    <input type="radio" wire:model.live="businessGroup" value="{{ $option->value }}" class="sr-only">
                    <span class="text-sm font-semibold text-ink">{{ $option->label() }}</span>
                    <span class="mt-1 text-xs text-ink-faint">{{ $option->description() }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>

    @if ($chosenGroup)
        <fieldset wire:key="types-{{ $chosenGroup->value }}">
            <legend class="block text-sm font-medium text-ink-muted">Which one fits you best?</legend>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($chosenGroup->signupCases() as $option)
                    <label wire:key="type-{{ $option->value }}" data-business-type="{{ $option->value }}" @class([
                        'cursor-pointer rounded-full border px-4 py-2 text-sm font-medium transition',
                        'border-link bg-primary-soft text-link ring-1 ring-link' => $category === $option->value,
                        'border-line text-ink-muted hover:border-line-strong' => $category !== $option->value,
                    ])>
                        <input type="radio" wire:model.live="businessCategory" value="{{ $option->value }}" class="sr-only">
                        {{ $option->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif

    @php($partners = \App\Enums\BusinessCategory::tryFrom($category)?->combinesWith() ?? [])
    @if ($partners !== [])
        <fieldset wire:key="addons-{{ $category }}" data-business-addons>
            <legend class="block text-sm font-medium text-ink-muted">Do you also run…</legend>
            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ($partners as $partner)
                    <x-ui.checkbox wire:model.live="businessAddons" :value="$partner->value" :label="$partner->label()" />
                @endforeach
            </div>
        </fieldset>
    @endif

    <p class="text-xs text-ink-faint">We’ll set up the screens your kind of business needs. You can change this later.</p>
    <x-ui.error :messages="$errors->get('businessCategory')" />
</div>
