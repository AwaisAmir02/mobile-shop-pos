<?php

use App\Enums\LimitKey;
use App\Enums\ShopScreen;
use App\Exceptions\UsageLimitReached;
use App\Livewire\Concerns\Toasts;
use App\Models\Branch;
use App\Models\ShopAccount;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Branches of the shop. Catalog, customers, vendors and stock are shared;
 * each branch records its own sales, stock-ins, udhaar, expenses and other
 * transactions. Main can't be archived, and a branch is archived rather
 * than deleted so its history keeps its label. Adding or reactivating a
 * branch counts against the plan's Branches limit.
 */
new #[Layout('layouts.app')] #[Title('Branches')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;

    public string $name = '';
    public string $address = '';
    public string $phone = '';

    protected function authorizeAccess(): void
    {
        abort_unless(Auth::user()->hasAccessTo(ShopScreen::Branches), 403);
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $entitlements = app(Entitlements::class);

        return [
            'branches' => Branch::query()->withCount(['users' => fn ($q) => $q->where('is_active', true)])->orderBy('archived_at')->ordered()->get(),
            'limit' => $entitlements->limit($shop, LimitKey::Branches),
            'used' => $entitlements->usage($shop, LimitKey::Branches),
        ];
    }

    public function openCreate(): void
    {
        $this->authorizeAccess();
        $this->resetForm();
        $this->dispatch('open-modal', name: 'branch-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorizeAccess();
        $this->resetForm();

        $branch = Branch::findOrFail($id);
        $this->editingId = $branch->id;
        $this->name = $branch->name;
        $this->address = (string) $branch->address;
        $this->phone = (string) $branch->phone;

        $this->dispatch('open-modal', name: 'branch-form');
    }

    public function save(): void
    {
        $this->authorizeAccess();

        $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('branches', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->editingId)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $data = [
            'name' => trim($this->name),
            'address' => trim($this->address) !== '' ? trim($this->address) : null,
            'phone' => trim($this->phone) !== '' ? trim($this->phone) : null,
        ];

        if ($this->editingId) {
            Branch::findOrFail($this->editingId)->update($data);
            $this->toastSuccess('Branch updated.');
        } else {
            try {
                $this->ensureRoomForAnotherBranch();
            } catch (UsageLimitReached $e) {
                $this->toastError($e->getMessage());

                return;
            }

            Branch::create($data + ['shop_id' => Auth::user()->shop_id]);
            $this->forgetMeters();
            $this->toastSuccess('Branch added.');
        }

        $this->dispatch('close-modal', name: 'branch-form');
        $this->resetForm();
    }

    public function archive(int $id): void
    {
        $this->authorizeAccess();
        $branch = Branch::findOrFail($id);

        if ($branch->is_main) {
            $this->toastError('The Main branch can’t be archived.');

            return;
        }

        if (User::where('shop_id', Auth::user()->shop_id)->where('branch_id', $branch->id)->where('is_active', true)->exists()) {
            $this->toastError('Move this branch’s staff to another branch (Team) before archiving it.');

            return;
        }

        if (ShopAccount::where('branch_id', $branch->id)->exists()) {
            $this->toastError('Move or remove this branch’s cash drawers (Settings › Shop Accounts) before archiving it.');

            return;
        }

        $branch->update(['archived_at' => now()]);
        $this->forgetMeters();
        $this->toastSuccess($branch->name.' archived. Its history stays as it is.');
    }

    public function reactivate(int $id): void
    {
        $this->authorizeAccess();
        $branch = Branch::findOrFail($id);

        try {
            $this->ensureRoomForAnotherBranch();
        } catch (UsageLimitReached $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $branch->update(['archived_at' => null]);
        $this->forgetMeters();
        $this->toastSuccess($branch->name.' is active again.');
    }

    protected function ensureRoomForAnotherBranch(): void
    {
        app(Entitlements::class)->ensureCanAdd(Auth::user()->shop, LimitKey::Branches);
    }

    protected function forgetMeters(): void
    {
        app(Entitlements::class)->forgetMeters(Auth::user()->shop);
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'branch-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset(['editingId', 'name', 'address', 'phone']);
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Branches</h1>
    </x-slot>

    <x-billing.limit-notice limit="branches" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500">
            @if ($limit !== null)
                {{ $used }} of {{ $limit }} {{ Str::plural('branch', $limit) }} on your plan.
            @else
                Products, customers, vendors and stock are shared by every branch. Sales, stock-ins, udhaar and expenses are recorded per branch.
            @endif
        </p>

        <x-ui.button wire:click="openCreate">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Branch
        </x-ui.button>
    </div>

    <x-ui.table :headers="['Branch', 'Address', 'Phone', 'Staff', '']">
        @foreach ($branches as $branch)
            <x-ui.table-row wire:key="branch-{{ $branch->id }}">
                <x-ui.table-cell class="font-medium text-slate-900">
                    {{ $branch->name }}
                    @if ($branch->is_main)
                        <x-ui.badge variant="brand">Main</x-ui.badge>
                    @endif
                    @if ($branch->isArchived())
                        <x-ui.badge>Archived</x-ui.badge>
                    @endif
                </x-ui.table-cell>
                <x-ui.table-cell>{{ $branch->address ?? '—' }}</x-ui.table-cell>
                <x-ui.table-cell>{{ $branch->phone ?? '—' }}</x-ui.table-cell>
                <x-ui.table-cell>{{ $branch->users_count ?: '—' }}</x-ui.table-cell>
                <x-ui.table-cell align="right">
                    <div class="flex justify-end gap-2">
                        <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $branch->id }})">Edit</x-ui.button>
                        @if ($branch->isArchived())
                            <x-ui.button size="sm" variant="ghost" wire:click="reactivate({{ $branch->id }})">Reactivate</x-ui.button>
                        @elseif (! $branch->is_main)
                            <x-ui.button size="sm" variant="ghost" wire:click="archive({{ $branch->id }})" wire:confirm="Archive {{ $branch->name }}? Its history stays; nothing new can be recorded there." class="text-red-600 hover:bg-red-50">Archive</x-ui.button>
                        @endif
                    </div>
                </x-ui.table-cell>
            </x-ui.table-row>
        @endforeach
    </x-ui.table>

    <x-ui.modal name="branch-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit Branch' : 'Add Branch' }}</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="branchName">
                    <x-ui.input wire:model="name" id="branchName" autofocus />
                </x-ui.field>

                <x-ui.field label="Address" name="address" for="branchAddress">
                    <x-ui.input wire:model="address" id="branchAddress" />
                </x-ui.field>

                <x-ui.field label="Phone" name="phone" for="branchPhone">
                    <x-ui.input wire:model="phone" id="branchPhone" type="tel" inputmode="tel" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Changes' : 'Add Branch' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
