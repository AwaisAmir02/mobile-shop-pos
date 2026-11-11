<?php

use App\Enums\PaymentAccountKind;
use App\Livewire\Concerns\Toasts;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Payment Accounts')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;
    public string $kind = 'bank';
    public string $provider = '';
    public string $accountTitle = '';
    public string $accountNumber = '';
    public string $iban = '';
    public string $note = '';
    public bool $isActive = true;

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'kind', 'provider', 'accountTitle', 'accountNumber', 'iban', 'note', 'isActive']);
    }

    public function openCreate(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetForm();
        $this->dispatch('open-modal', name: 'payment-account-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorizeSuperAdmin();
        $this->resetForm();

        $account = PaymentAccount::findOrFail($id);
        $this->editingId = $account->id;
        $this->kind = $account->kind->value;
        $this->provider = (string) $account->provider;
        $this->accountTitle = $account->account_title;
        $this->accountNumber = $account->account_number;
        $this->iban = (string) $account->iban;
        $this->note = (string) $account->note;
        $this->isActive = $account->is_active;

        $this->dispatch('open-modal', name: 'payment-account-form');
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();

        $kind = PaymentAccountKind::tryFrom($this->kind);

        $this->validate([
            'kind' => ['required', Rule::enum(PaymentAccountKind::class)],
            'provider' => [$kind?->needsProvider() ? 'required' : 'nullable', 'string', 'max:80'],
            'accountTitle' => ['required', 'string', 'max:120'],
            'accountNumber' => ['required', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'provider' => $kind === PaymentAccountKind::Bank ? 'bank name' : 'name',
            'accountTitle' => 'account title',
            'accountNumber' => $kind === PaymentAccountKind::Bank ? 'account number' : 'mobile account number',
            'iban' => 'IBAN',
        ]);

        $clean = fn (string $value) => trim($value) !== '' ? trim($value) : null;

        $data = [
            'kind' => $kind->value,
            'provider' => $kind->needsProvider() ? $clean($this->provider) : null,
            'account_title' => trim($this->accountTitle),
            'account_number' => trim($this->accountNumber),
            'iban' => $kind === PaymentAccountKind::Bank ? $clean($this->iban) : null,
            'note' => $clean($this->note),
            'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            PaymentAccount::findOrFail($this->editingId)->update($data);
            $this->toastSuccess('Payment account updated.');
        } else {
            PaymentAccount::create($data + ['sort_order' => (int) PaymentAccount::max('sort_order') + 1]);
            $this->toastSuccess('Payment account added. Shops see it when they pay.');
        }

        $this->dispatch('close-modal', name: 'payment-account-form');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeSuperAdmin();

        $account = PaymentAccount::findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);

        $this->toastSuccess($account->is_active ? 'Shops see this account again.' : 'Hidden from shops.');
    }

    public function move(int $id, string $direction): void
    {
        $this->authorizeSuperAdmin();

        DB::transaction(function () use ($id, $direction) {
            $ids = PaymentAccount::ordered()->lockForUpdate()->pluck('id')->all();
            $position = array_search($id, $ids, true);
            $target = $direction === 'up' ? $position - 1 : $position + 1;

            if ($position === false || ! isset($ids[$target])) {
                return;
            }

            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

            foreach ($ids as $index => $accountId) {
                PaymentAccount::whereKey($accountId)->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function delete(int $id): void
    {
        $this->authorizeSuperAdmin();

        PaymentAccount::findOrFail($id)->delete();
        $this->toastSuccess('Payment account deleted.');
    }

    public function with(): array
    {
        $this->authorizeSuperAdmin();

        return [
            'accounts' => PaymentAccount::ordered()->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Where shops send their plan and add-on payments">Payment Accounts</x-ui.page-header>
    </x-slot>

    <x-ui.card title="Your accounts" description="Shown to shop owners on Plan & Usage when they pay, in this order. They then upload the transfer slip for you to verify under Payments.">
        <x-slot name="actions">
            <x-ui.button size="sm" wire:click="openCreate">Add Account</x-ui.button>
        </x-slot>

        @if ($accounts->isEmpty())
            <x-ui.empty-state title="No payment accounts yet" description="Until you add one, shops see the fallback bank details from the server settings (BILLING_BANK_INSTRUCTIONS)." />
        @else
            <x-ui.table stack :headers="['Order', 'Account', 'Number', 'Status', '']">
                @foreach ($accounts as $account)
                    <x-ui.table-row wire:key="account-{{ $account->id }}">
                        <x-ui.table-cell primary>
                            <div class="flex gap-1">
                                <button type="button" wire:click="move({{ $account->id }}, 'up')" @disabled($loop->first) class="rounded p-1 text-ink-faint hover:bg-surface-sunken disabled:opacity-30" aria-label="Move {{ $account->heading() }} up">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" wire:click="move({{ $account->id }}, 'down')" @disabled($loop->last) class="rounded p-1 text-ink-faint hover:bg-surface-sunken disabled:opacity-30" aria-label="Move {{ $account->heading() }} down">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                            </div>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Account">
                            <p class="font-medium text-ink">{{ $account->heading() }}</p>
                            <p class="text-xs text-ink-faint">{{ $account->account_title }}</p>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Number" class="font-mono text-xs">
                            {{ $account->account_number }}
                            @if ($account->iban)
                                <span class="block text-ink-faint">{{ $account->iban }}</span>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Status"><x-ui.badge :variant="$account->is_active ? 'success' : 'neutral'">{{ $account->is_active ? 'Shown' : 'Hidden' }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell actions>
                            <x-ui.row-actions label="Actions for {{ $account->heading() }}">
                                <x-ui.menu-item wire:click="openEdit({{ $account->id }})">Edit</x-ui.menu-item>
                                <x-ui.menu-item wire:click="toggleActive({{ $account->id }})">{{ $account->is_active ? 'Hide' : 'Show' }}</x-ui.menu-item>
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="delete({{ $account->id }})" confirm="Delete the {{ $account->heading() }} account? Shops stop seeing it straight away." confirm-label="Delete">Delete</x-ui.menu-item>
                            </x-ui.row-actions>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="payment-account-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-ink">{{ $editingId ? 'Edit Payment Account' : 'Add Payment Account' }}</h2>

            <div class="mt-5 space-y-5">
                <fieldset>
                    <legend class="text-sm font-medium text-ink-muted">Type</legend>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach (PaymentAccountKind::cases() as $option)
                            <label @class(['flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm', 'border-link bg-primary-soft' => $kind === $option->value, 'border-line' => $kind !== $option->value])>
                                <input type="radio" wire:model.live="kind" value="{{ $option->value }}" class="text-link focus:ring-link"> {{ $option->label() }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if (PaymentAccountKind::tryFrom($kind)?->needsProvider())
                    <x-ui.field :label="$kind === 'bank' ? 'Bank name' : 'Name'" name="provider" for="accountProvider">
                        <x-ui.input wire:model="provider" id="accountProvider" maxlength="80" :placeholder="$kind === 'bank' ? 'e.g. Meezan Bank' : 'e.g. SadaPay'" />
                    </x-ui.field>
                @endif

                <x-ui.field label="Account title" name="accountTitle" for="accountTitle" help="The name the customer will see when they send money">
                    <x-ui.input wire:model="accountTitle" id="accountTitle" maxlength="120" />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="$kind === 'bank' ? 'Account number' : 'Mobile account number'" name="accountNumber" for="accountNumber">
                        <x-ui.input wire:model="accountNumber" id="accountNumber" maxlength="60" />
                    </x-ui.field>
                    @if ($kind === 'bank')
                        <x-ui.field label="IBAN" name="iban" for="accountIban">
                            <x-ui.input wire:model="iban" id="accountIban" maxlength="40" placeholder="PK00 XXXX …" />
                        </x-ui.field>
                    @endif
                </div>

                <x-ui.field label="Note" name="note" for="accountNote" help="Optional, e.g. branch or which payments this is for">
                    <x-ui.input wire:model="note" id="accountNote" maxlength="255" />
                </x-ui.field>

                <x-ui.checkbox wire:model="isActive" label="Show to shops" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'payment-account-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Account' : 'Add Account' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
