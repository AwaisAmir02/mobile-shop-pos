<?php

use App\Enums\ShopScreen;
use App\Livewire\Concerns\Toasts;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Roles')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;

    public string $name = '';
    public array $permissions = [];

    public function with(): array
    {
        return [
            'roles' => Role::query()->withCount('users')->orderBy('name')->get(),
            'screens' => ShopScreen::cases(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'role-form');
    }

    public function openEdit(int $id): void
    {
        $role = Role::findOrFail($id);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->permissions = $role->permissions ?? [];

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'role-form');
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('shop_id', auth()->user()->shop_id)->ignore($this->editingId)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::enum(ShopScreen::class)],
        ]);

        $role = $this->editingId ? Role::findOrFail($this->editingId) : new Role;

        $role->fill([
            'name' => $this->name,
            'permissions' => array_values($this->permissions),
        ])->save();

        $this->toastSuccess($this->editingId ? 'Role updated.' : 'Role created.');
        $this->dispatch('close-modal', name: 'role-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->users_count > 0) {
            $this->toastError("Cannot delete \"{$role->name}\" — it's assigned to {$role->users_count} user(s). Reassign them first.");

            return;
        }

        $role->delete();
        $this->toastSuccess('Role deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'role-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'permissions']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Roles</h1>
            <a href="{{ route('users.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                Users →
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex justify-end">
        <x-ui.button wire:click="openCreate">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Role
        </x-ui.button>
    </div>

    @if ($roles->isEmpty())
        <x-ui.empty-state
            title="No roles yet"
            description="Create a role to control what staff can access, then assign it when adding a user."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openCreate">Add Role</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Role', 'Screen Access', 'Users', '']">
            @foreach ($roles as $role)
                <x-ui.table-row wire:key="role-{{ $role->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $role->name }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (ShopScreen::cases() as $screen)
                                @if ($role->hasAccess($screen))
                                    <x-ui.badge variant="brand">{{ $screen->label() }}</x-ui.badge>
                                @endif
                            @endforeach
                            @if (empty($role->permissions))
                                <span class="text-sm text-slate-400">No access</span>
                            @endif
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $role->users_count }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $role->id }})">
                                Edit
                            </x-ui.button>
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="delete({{ $role->id }})"
                                wire:confirm="Delete the {{ $role->name }} role? This cannot be undone."
                                class="text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="role-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit Role' : 'Add Role' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Role Name" name="name" for="name">
                    <x-ui.input wire:model="name" id="name" placeholder="e.g. Cashier, Manager" autofocus />
                </x-ui.field>

                <x-ui.field label="Screen Access" name="permissions">
                    <div class="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-4">
                        @foreach ($screens as $screen)
                            <x-ui.checkbox wire:model="permissions" value="{{ $screen->value }}" :label="$screen->label()" />
                        @endforeach
                    </div>
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add Role' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
