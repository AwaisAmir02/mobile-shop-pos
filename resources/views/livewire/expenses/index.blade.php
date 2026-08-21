<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Expenses')] class extends Component
{
    use Toasts, WithPagination;

    public string $description = '';
    public string $amount = '';
    public string $expenseDate = '';

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->expenseDate = now()->toDateString();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return Expense::query()
            ->when($this->from, fn ($query) => $query->whereDate('expense_date', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('expense_date', '<=', $this->to));
    }

    public function with(): array
    {
        return [
            'expenses' => $this->filteredQuery()->latest('expense_date')->latest('id')->paginate(10),
            'totalExpenses' => $this->filteredQuery()->sum('amount'),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expenseDate' => ['required', 'date'],
        ]);

        Expense::create([
            'user_id' => Auth::id(),
            'description' => $this->description,
            'amount' => $this->amount,
            'expense_date' => $this->expenseDate,
        ]);

        $this->toastSuccess('Expense logged.');
        $this->reset(['description', 'amount']);
        $this->expenseDate = now()->toDateString();
        $this->resetPage();
    }

    public function clearDateFilters(): void
    {
        $this->reset(['from', 'to']);
    }

    public function delete(int $expenseId): void
    {
        Expense::findOrFail($expenseId)->delete();

        $this->toastSuccess('Expense deleted.');
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Expenses</h1>
    </x-slot>

    <x-ui.card title="Log an Expense">
        <form wire:submit="save" class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <x-ui.field label="Description" name="description" for="description" class="flex-1">
                <x-ui.input wire:model="description" id="description" placeholder="e.g. Shop electricity bill" autofocus />
            </x-ui.field>

            <x-ui.field label="Amount" name="amount" for="amount" class="sm:w-40">
                <x-ui.input wire:model="amount" id="amount" type="number" min="0.01" step="0.01" />
            </x-ui.field>

            <x-ui.field label="Date" name="expenseDate" for="expenseDate" class="sm:w-48">
                <x-ui.input wire:model="expenseDate" id="expenseDate" type="date" />
            </x-ui.field>

            <div class="sm:pt-7">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" class="w-full justify-center sm:w-auto">
                    Save
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mt-6 mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            @if ($from || $to)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearDateFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.card :padding="false" class="w-full sm:w-auto">
            <div class="px-5 py-3 text-right">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Expenses</p>
                <p class="text-display-sm text-slate-900">Rs {{ number_format($totalExpenses, 2) }}</p>
            </div>
        </x-ui.card>
    </div>

    @if ($expenses->isEmpty())
        <x-ui.empty-state
            title="No expenses logged"
            description="Expenses you log above will show up here."
        />
    @else
        <x-ui.table :headers="['Date', 'Description', 'Amount', '']">
            @foreach ($expenses as $expense)
                <x-ui.table-row wire:key="expense-{{ $expense->id }}">
                    <x-ui.table-cell>{{ $expense->expense_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $expense->description }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($expense->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button
                            size="sm"
                            variant="ghost"
                            wire:click="delete({{ $expense->id }})"
                            wire:confirm="Delete this expense?"
                            class="text-red-600 hover:bg-red-50"
                        >
                            Delete
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $expenses->links() }}
        </div>
    @endif
</div>
