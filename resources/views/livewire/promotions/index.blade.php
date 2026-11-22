<?php

use App\Actions\Promotions;
use App\Enums\SaleStatus;
use App\Enums\ShopScreen;
use App\Livewire\Concerns\Toasts;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\SaleItem;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Promotions (v1): a percentage or flat amount off, between two dates, on
 * chosen products, variant groups, categories or everything. The register
 * applies them by itself (App\Actions\Promotions); WhatsApp and online
 * orders don't get them.
 *
 * Once a sale has used one, what it takes off and what it covers are fixed,
 * so a sale's edit works it out the same way; its name and dates can still
 * change, it can be paused, but not deleted.
 */
new #[Layout('layouts.app')] #[Title('Promotions')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;

    public string $name = '';
    public string $kind = Promotion::PERCENT;
    public string $value = '';
    public string $appliesTo = Promotion::ALL;
    /** @var array<int, string> product or group ids, or category slugs */
    public array $targets = [];
    public string $startsOn = '';
    public string $endsOn = '';
    public string $targetSearch = '';

    protected function authorizeAccess(): void
    {
        abort_unless(Auth::user()->hasAccessTo(ShopScreen::Promotions), 403);
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function with(): array
    {
        $today = BatchExpiry::today();
        $promotions = Promotion::query()->latest('starts_on')->latest('id')->get();

        // What each has taken off so far, on sales that still stand.
        $use = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->whereIn('sale_items.promotion_id', $promotions->pluck('id'))
            ->groupBy('sale_items.promotion_id')
            ->select('sale_items.promotion_id', DB::raw('COUNT(DISTINCT sale_items.sale_id) as sales'), DB::raw('SUM(sale_items.promotion_discount) as given'))
            ->get()
            ->keyBy('promotion_id');

        return [
            'today' => $today,
            'promotions' => $promotions,
            'use' => $use,
            'running' => $promotions->filter->runsOn($today)->count(),
            'categories' => MainCategory::query()->orderBy('name')->get(),
            'matches' => $this->targetMatches(),
            'chosen' => $this->chosenTargets(),
            'locked' => $this->editingId !== null && Promotion::findOrFail($this->editingId)->hasBeenUsed(),
        ];
    }

    /** "Everything", "Category: Shirts", "3 products"… */
    public function coverageLabel(Promotion $promotion): string
    {
        $count = count((array) $promotion->targets);

        return match ($promotion->applies_to) {
            Promotion::ALL => 'Everything',
            Promotion::CATEGORIES => $count === 1
                ? 'Category: '.(MainCategory::query()->where('slug', $promotion->targets[0])->value('name') ?? $promotion->targets[0])
                : $count.' categories',
            Promotion::GROUPS => $count === 1
                ? (ProductGroup::query()->whereKey($promotion->targets[0])->value('name') ?? '1 variant group')
                : $count.' variant groups',
            default => $count === 1
                ? (Product::query()->whereKey($promotion->targets[0])->value('name') ?? '1 product')
                : $count.' products',
        };
    }

    protected function targetMatches()
    {
        $term = trim($this->targetSearch);

        if ($term === '' || ! in_array($this->appliesTo, [Promotion::PRODUCTS, Promotion::GROUPS], true)) {
            return collect();
        }

        $query = $this->appliesTo === Promotion::GROUPS ? ProductGroup::query() : Product::query();

        return $query->where('name', 'like', '%'.$term.'%')
            ->whereNotIn('id', $this->targets)
            ->orderBy('name')->limit(8)->get(['id', 'name']);
    }

    /** @return array<string, string> id => name of the products or groups picked */
    protected function chosenTargets(): array
    {
        if (! in_array($this->appliesTo, [Promotion::PRODUCTS, Promotion::GROUPS], true) || $this->targets === []) {
            return [];
        }

        $query = $this->appliesTo === Promotion::GROUPS ? ProductGroup::query() : Product::query();

        return $query->whereIn('id', $this->targets)->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
    }

    public function updatedAppliesTo(): void
    {
        $this->targets = [];
        $this->targetSearch = '';
    }

    public function addTarget(int $id): void
    {
        if (! in_array((string) $id, $this->targets, true)) {
            $this->targets[] = (string) $id;
        }

        $this->targetSearch = '';
    }

    public function removeTarget(string $id): void
    {
        $this->targets = array_values(array_filter($this->targets, fn ($target) => $target !== $id));
    }

    public function openCreate(): void
    {
        $this->authorizeAccess();
        $this->resetForm();
        $this->startsOn = BatchExpiry::today();
        $this->dispatch('open-modal', name: 'promotion-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorizeAccess();
        $this->resetForm();

        $promotion = Promotion::findOrFail($id);
        $this->editingId = $promotion->id;
        $this->name = $promotion->name;
        $this->kind = $promotion->kind;
        $this->value = rtrim(rtrim((string) $promotion->value, '0'), '.');
        $this->appliesTo = $promotion->applies_to;
        $this->targets = array_map('strval', (array) $promotion->targets);
        $this->startsOn = $promotion->starts_on->toDateString();
        $this->endsOn = $promotion->ends_on?->toDateString() ?? '';

        $this->dispatch('open-modal', name: 'promotion-form');
    }

    public function save(): void
    {
        $this->authorizeAccess();
        $shopId = Auth::user()->shop_id;
        $editing = $this->editingId !== null ? Promotion::findOrFail($this->editingId) : null;
        $locked = $editing?->hasBeenUsed() ?? false;

        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in([Promotion::PERCENT, Promotion::FLAT])],
            'value' => array_merge(['required', 'numeric', 'gt:0'], $this->kind === Promotion::PERCENT ? ['max:99'] : ['max:9999999']),
            'appliesTo' => ['required', Rule::in([Promotion::ALL, Promotion::PRODUCTS, Promotion::GROUPS, Promotion::CATEGORIES])],
            'targets' => [$this->appliesTo === Promotion::ALL ? 'nullable' : 'required', 'array'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
        ], [
            'targets.required' => match ($this->appliesTo) {
                Promotion::PRODUCTS => 'Pick at least one product.',
                Promotion::GROUPS => 'Pick at least one variant group.',
                default => 'Pick at least one category.',
            },
            'value.max' => $this->kind === Promotion::PERCENT ? 'A percentage can be at most 99.' : 'That amount is too large.',
        ], ['endsOn' => 'end date', 'startsOn' => 'start date', 'value' => $this->kind === Promotion::PERCENT ? 'percentage' : 'amount']);

        // Only the shop's own products, groups and categories.
        $targets = match ($this->appliesTo) {
            Promotion::PRODUCTS => Product::query()->whereIn('id', $this->targets)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            Promotion::GROUPS => ProductGroup::query()->whereIn('id', $this->targets)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            Promotion::CATEGORIES => MainCategory::query()->whereIn('slug', $this->targets)->pluck('slug')->all(),
            default => null,
        };

        $data = [
            'name' => trim($this->name),
            'kind' => $this->kind,
            'value' => round((float) $this->value, 2),
            'applies_to' => $this->appliesTo,
            'targets' => $targets !== null ? array_values($targets) : null,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn !== '' ? $this->endsOn : null,
        ];

        if ($locked && ($data['kind'] !== $editing->kind || (float) $data['value'] !== (float) $editing->value
            || $data['applies_to'] !== $editing->applies_to || array_values((array) $data['targets']) !== array_values(array_map('strval', (array) $editing->targets)))) {
            throw ValidationException::withMessages(['value' => 'Sales have already used this promotion, so what it takes off and what it covers can\'t change. End it and add a new one.']);
        }

        if ($editing !== null) {
            $editing->update($data);
            $this->toastSuccess('Promotion saved.');
        } else {
            Promotion::create($data + ['shop_id' => $shopId, 'created_by' => Auth::id()]);
            $this->toastSuccess('Promotion added. The register applies it by itself.');
        }

        $this->dispatch('close-modal', name: 'promotion-form');
        $this->resetForm();
    }

    public function togglePause(int $id): void
    {
        $this->authorizeAccess();
        $promotion = Promotion::findOrFail($id);
        $promotion->update(['is_active' => ! $promotion->is_active]);
        $this->toastSuccess($promotion->is_active ? $promotion->name.' is on again.' : $promotion->name.' paused. New sales won\'t get it.');
    }

    public function delete(int $id): void
    {
        $this->authorizeAccess();
        $promotion = Promotion::findOrFail($id);

        if ($promotion->hasBeenUsed()) {
            $this->toastError('Sales have used '.$promotion->name.', so it stays on record. Pause it or set an end date instead.');

            return;
        }

        $promotion->delete();
        $this->toastSuccess('Promotion deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'promotion-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset(['editingId', 'name', 'kind', 'value', 'appliesTo', 'targets', 'startsOn', 'endsOn', 'targetSearch']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Promotions</x-ui.page-header>
    </x-slot>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-faint">
            New Sale takes the promotion off each line it covers, by itself; a line gets the one that takes off most. WhatsApp and online store orders don't get promotions.
            @if ($running > 0)
                <span class="font-semibold text-ink">{{ $running }} running today.</span>
            @endif
        </p>

        <x-ui.button wire:click="openCreate">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Promotion
        </x-ui.button>
    </div>

    @if ($promotions->isEmpty())
        <x-ui.empty-state title="No promotions yet" description="Add one for a sale season, a slow-moving item or a whole category, with the dates it runs." />
    @else
        <x-ui.table stack :headers="['Promotion', 'Covers', 'Dates', 'Status', 'Given so far', '']">
            @foreach ($promotions as $promotion)
                @php($status = $promotion->statusOn($today))
                <x-ui.table-row wire:key="promotion-{{ $promotion->id }}" data-promotion-row="{{ $promotion->id }}">
                    <x-ui.table-cell primary class="font-medium text-ink">
                        {{ $promotion->name }}
                        <span class="block text-xs font-normal text-ink-faint">{{ $promotion->offerLabel() }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Covers">{{ $this->coverageLabel($promotion) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Dates">{{ $promotion->starts_on->format('d M Y') }} – {{ $promotion->ends_on?->format('d M Y') ?? 'until stopped' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <x-ui.badge :variant="$status === 'Running' ? 'success' : 'neutral'">{{ $status }}</x-ui.badge>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Given so far">
                        @if ($row = $use->get($promotion->id))
                            Rs {{ number_format((float) $row->given, 2) }} on {{ $row->sales }} {{ Str::plural('sale', (int) $row->sales) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $promotion->name }}">
                            <x-ui.menu-item wire:click="openEdit({{ $promotion->id }})">Edit</x-ui.menu-item>
                            <x-ui.menu-item wire:click="togglePause({{ $promotion->id }})">{{ $promotion->is_active ? 'Pause' : 'Turn back on' }}</x-ui.menu-item>
                            @unless ($use->has($promotion->id))
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="delete({{ $promotion->id }})" confirm="Delete {{ $promotion->name }}?" confirm-label="Delete">Delete</x-ui.menu-item>
                            @endunless
                        </x-ui.row-actions>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="promotion-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-ink">{{ $editingId ? 'Edit Promotion' : 'Add Promotion' }}</h2>
            @if ($locked)
                <x-ui.note class="mt-3">Sales have used this promotion, so only its name and dates can change.</x-ui.note>
            @endif

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="promotionName" help="Shown on the receipt, e.g. Eid Sale">
                    <x-ui.input wire:model="name" id="promotionName" maxlength="60" autofocus />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Discount" name="kind">
                        <x-ui.segmented name="promotion-kind" wire:model.live="kind" :options="['percent' => '% off', 'flat' => 'Rs off each']" label="Discount type" @class(['pointer-events-none opacity-60' => $locked]) />
                    </x-ui.field>
                    <x-ui.field :label="$kind === 'percent' ? 'Percentage' : 'Rupees off each piece (or kg)'" name="value" for="promotionValue">
                        <x-ui.input wire:model="value" id="promotionValue" type="number" min="0" step="0.01" :readonly="$locked" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Applies to" name="appliesTo">
                    <x-ui.segmented name="promotion-applies-to" wire:model.live="appliesTo" :options="['all' => 'Everything', 'products' => 'Products', 'groups' => 'Variant groups', 'categories' => 'Categories']" label="Applies to" @class(['pointer-events-none opacity-60' => $locked]) />
                </x-ui.field>

                @if ($appliesTo === 'categories')
                    <x-ui.field label="Categories" name="targets">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2" data-promotion-categories>
                            @foreach ($categories as $category)
                                <x-ui.checkbox wire:model="targets" :value="$category->slug">{{ $category->name }}</x-ui.checkbox>
                            @endforeach
                        </div>
                    </x-ui.field>
                @elseif (in_array($appliesTo, ['products', 'groups'], true))
                    <x-ui.field :label="$appliesTo === 'groups' ? 'Variant groups' : 'Products'" name="targets" for="targetSearch">
                        @if ($chosen !== [])
                            <div class="mb-2 flex flex-wrap gap-1.5" data-promotion-targets>
                                @foreach ($chosen as $id => $targetName)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-semibold text-ink" wire:key="target-{{ $id }}">
                                        {{ $targetName }}
                                        @unless ($locked)
                                            <button type="button" wire:click="removeTarget('{{ $id }}')" class="text-ink-faint hover:text-ink" aria-label="Remove {{ $targetName }}">×</button>
                                        @endunless
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @unless ($locked)
                            <x-ui.input wire:model.live.debounce.300ms="targetSearch" id="targetSearch" placeholder="Search by name" autocomplete="off" />
                            @if ($matches->isNotEmpty())
                                <ul class="mt-1 divide-y divide-line rounded-lg border border-line bg-surface">
                                    @foreach ($matches as $match)
                                        <li wire:key="match-{{ $match->id }}">
                                            <button type="button" wire:click="addTarget({{ $match->id }})" class="w-full px-3 py-2 text-left text-sm text-ink hover:bg-surface-sunken">{{ $match->name }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endunless
                    </x-ui.field>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Starts" name="startsOn" for="startsOn">
                        <x-ui.input wire:model="startsOn" id="startsOn" type="date" />
                    </x-ui.field>
                    <x-ui.field label="Ends" name="endsOn" for="endsOn" help="Blank runs until you pause it">
                        <x-ui.input wire:model="endsOn" id="endsOn" type="date" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Changes' : 'Add Promotion' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
