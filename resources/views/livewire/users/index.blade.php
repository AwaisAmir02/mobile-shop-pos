<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Users')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;

    public string $name = '';
    public string $email = '';
    public ?int $roleId = null;

    public ?int $resettingId = null;
    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    public function with(): array
    {
        $shopId = Auth::user()->shop_id;

        return [
            'users' => User::where('shop_id', $shopId)->with('role')->orderByDesc('is_owner')->orderBy('name')->get(),
            'roles' => Role::orderBy('name')->get(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'user-form');
    }

    public function openEdit(int $id): void
    {
        $user = User::where('shop_id', Auth::user()->shop_id)->findOrFail($id);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->roleId = $user->role_id;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'user-form');
    }

    public function save(): void
    {
        $editingOwner = $this->editingId && User::find($this->editingId)?->is_owner;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'roleId' => [$editingOwner ? 'nullable' : 'required', 'integer', Rule::exists('roles', 'id')->where('shop_id', Auth::user()->shop_id)],
        ]);

        if ($this->editingId) {
            $user = User::where('shop_id', Auth::user()->shop_id)->findOrFail($this->editingId);

            $user->update([
                'name' => $this->name,
                'email' => $this->email,
                'role_id' => $user->is_owner ? null : $this->roleId,
            ]);

            $this->toastSuccess('User updated.');
        } else {
            User::create([
                'shop_id' => Auth::user()->shop_id,
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make(str()->random(24)),
                'role_id' => $this->roleId,
                'is_owner' => false,
                'email_verified_at' => now(),
            ]);

            $this->toastSuccess('User created. Set their password from the reset action below.');
        }

        $this->dispatch('close-modal', name: 'user-form');
        $this->resetForm();
    }

    public function openResetPassword(int $id): void
    {
        $this->resettingId = $id;
        $this->reset(['newPassword', 'newPassword_confirmation']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'reset-password-form');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'newPassword' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::where('shop_id', Auth::user()->shop_id)->findOrFail($this->resettingId);
        $user->update(['password' => Hash::make($this->newPassword)]);

        $this->toastSuccess("Password reset for {$user->email}.");
        $this->dispatch('close-modal', name: 'reset-password-form');
        $this->reset(['resettingId', 'newPassword', 'newPassword_confirmation']);
    }

    public function delete(int $id): void
    {
        $user = User::where('shop_id', Auth::user()->shop_id)->findOrFail($id);

        if ($user->is_owner) {
            $this->toastError('The shop owner account cannot be deleted.');

            return;
        }

        if ($user->id === Auth::id()) {
            $this->toastError('You cannot delete your own account.');

            return;
        }

        $user->delete();
        $this->toastSuccess('User deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'user-form');
        $this->resetForm();
    }

    public function closeResetPasswordForm(): void
    {
        $this->dispatch('close-modal', name: 'reset-password-form');
        $this->reset(['resettingId', 'newPassword', 'newPassword_confirmation']);
        $this->resetErrorBag();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'roleId']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Users</h1>
            <a href="{{ route('roles.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                Manage Roles →
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex justify-end">
        <x-ui.button wire:click="openCreate">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add User
        </x-ui.button>
    </div>

    <x-ui.table :headers="['Name', 'Email', 'Role', '']">
        @foreach ($users as $user)
            <x-ui.table-row wire:key="user-{{ $user->id }}">
                <x-ui.table-cell class="font-medium text-slate-900">{{ $user->name }}</x-ui.table-cell>
                <x-ui.table-cell>{{ $user->email }}</x-ui.table-cell>
                <x-ui.table-cell>
                    @if ($user->is_owner)
                        <x-ui.badge variant="brand">Owner · Full Access</x-ui.badge>
                    @else
                        <x-ui.badge>{{ $user->role?->name ?? 'No role' }}</x-ui.badge>
                    @endif
                </x-ui.table-cell>
                <x-ui.table-cell align="right">
                    <div class="flex justify-end gap-2">
                        <x-ui.button size="sm" variant="ghost" wire:click="openResetPassword({{ $user->id }})">
                            Reset Password
                        </x-ui.button>

                        @unless ($user->is_owner)
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $user->id }})">
                                Edit
                            </x-ui.button>
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="delete({{ $user->id }})"
                                wire:confirm="Delete {{ $user->name }}? This cannot be undone."
                                class="text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </x-ui.button>
                        @endunless
                    </div>
                </x-ui.table-cell>
            </x-ui.table-row>
        @endforeach
    </x-ui.table>

    <x-ui.modal name="user-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit User' : 'Add User' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="name">
                    <x-ui.input wire:model="name" id="name" autofocus />
                </x-ui.field>

                <x-ui.field label="Email" name="email" for="email">
                    <x-ui.input wire:model="email" id="email" type="email" />
                </x-ui.field>

                <x-ui.field label="Role" name="roleId" for="roleId">
                    <x-ui.select wire:model="roleId" id="roleId">
                        <option value="">Select a role</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @unless ($editingId)
                    <p class="text-sm text-slate-500">
                        A temporary password is generated automatically — use "Reset Password" from the list afterward to set the one you'll share with them.
                    </p>
                @endunless
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add User' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="reset-password-form" max-width="sm">
        <form wire:submit="resetPassword" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Reset Password</h2>
            <p class="mt-1 text-sm text-slate-500">Sets a new password without needing the current one.</p>

            <div class="mt-5 space-y-5">
                <x-ui.field label="New Password" name="newPassword" for="newPassword">
                    <x-ui.input wire:model="newPassword" id="newPassword" type="password" autocomplete="new-password" />
                </x-ui.field>

                <x-ui.field label="Confirm Password" name="newPassword_confirmation" for="newPassword_confirmation">
                    <x-ui.input wire:model="newPassword_confirmation" id="newPassword_confirmation" type="password" autocomplete="new-password" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeResetPasswordForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="resetPassword">
                    Reset Password
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
