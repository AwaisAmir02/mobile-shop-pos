<?php

use App\Actions\CancelExpense;
use App\Actions\UpdateExpense;
use App\Livewire\Concerns\Toasts;
use App\Models\Expense;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Expenses')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts, WithPagination;

    public string $description = '';
    public string $amount = '';
    public string $expenseDate = '';

    public string $from = '';
    public string $to = '';

    public ?int $editingId = null;
    public string $editDescription = '';
    public string $editAmount = '';
    public string $editExpenseDate = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

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
        return Expense::withoutGlobalScope(ExcludesCancelledScope::class)
            ->when($this->from, fn ($query) => $query->whereDate('expense_date', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('expense_date', '<=', $this->to));
    }

    public function with(): array
    {
        return [
            'expenses' => $this->filteredQuery()->latest('expense_date')->latest('id')->paginate(10),
            'totalExpenses' => $this->filteredQuery()->where('status', '!=', 'cancelled')->sum('amount'),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expenseDate' => ['required', 'date'],
        ]);

        [$paymentMethod, $shopAccountId] = $this->paidFromParts();

        Expense::create([
            'payment_method' => $paymentMethod,
            'shop_account_id' => $shopAccountId,
            'user_id' => Auth::id(),
            'description' => $this->description,
            'amount' => $this->amount,
            'expense_date' => $this->expenseDate,
        ]);

        $this->toastSuccess('Expense logged.');
        $this->reset(['description', 'amount', 'paidFrom']);
        $this->expenseDate = now()->toDateString();
        $this->resetPage();
    }

    public function clearDateFilters(): void
    {
        $this->reset(['from', 'to']);
    }

    public function openEdit(int $expenseId): void
    {
        abort_unless(Auth::user()->hasAccessTo('expenses-edit-cancel'), 403);

        $expense = Expense::findOrFail($expenseId);

        $this->editingId = $expense->id;
        $this->editDescription = $expense->description;
        $this->editAmount = (string) $expense->amount;
        $this->editExpenseDate = $expense->expense_date->toDateString();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-expense-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('expenses-edit-cancel'), 403);

        $this->validate([
            'editDescription' => ['required', 'string', 'max:255'],
            'editAmount' => ['required', 'numeric', 'min:0.01'],
            'editExpenseDate' => ['required', 'date'],
        ]);

        $expense = Expense::findOrFail($this->editingId);

        UpdateExpense::handle($expense, $this->editDescription, (float) $this->editAmount, $this->editExpenseDate, Auth::id());

        $this->toastSuccess('Expense updated.');
        $this->dispatch('close-modal', name: 'edit-expense-form');
        $this->reset(['editingId', 'editDescription', 'editAmount', 'editExpenseDate']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-expense-form');
        $this->reset(['editingId', 'editDescription', 'editAmount', 'editExpenseDate']);
    }

    public function openCancel(int $expenseId): void
    {
        abort_unless(Auth::user()->hasAccessTo('expenses-edit-cancel'), 403);

        $this->cancelingId = $expenseId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-expense-form');
    }

    public function cancelExpense(): void
    {
        abort_unless(Auth::user()->hasAccessTo('expenses-edit-cancel'), 403);

        $expense = Expense::findOrFail($this->cancelingId);
        CancelExpense::handle($expense, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-expense-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Expense cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-expense-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Expenses</x-ui.page-header>
    </x-slot>

    {{-- Quick-add row: one-line records go straight in above their list (docs/design-system.md). --}}
    <x-ui.card title="Log an Expense" class="mb-6">
        <form wire:submit="save" class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start">
            <x-ui.field label="Description" name="description" for="description" class="min-w-[12rem] flex-1" required>
                <x-ui.input wire:model="description" id="description" placeholder="e.g. Shop electricity bill" autofocus />
            </x-ui.field>

            <x-ui.field label="Amount" name="amount" for="amount" class="sm:w-40" required>
                <x-ui.input wire:model="amount" id="amount" type="number" min="0.01" step="0.01" />
            </x-ui.field>

            <x-ledger.paid-from :value="$paidFrom" class="sm:w-48" />

            <x-ui.field label="Date" name="expenseDate" for="expenseDate" class="sm:w-44" required>
                <x-ui.input wire:model="expenseDate" id="expenseDate" type="date" />
            </x-ui.field>

            <div class="sm:pt-7">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" class="w-full justify-center sm:w-auto">
                    Save
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to)">
        <x-ui.date-range />

        @if ($from || $to)
            <x-ui.button type="button" variant="ghost" wire:click="clearDateFilters">
                Clear
            </x-ui.button>
        @endif
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="Total Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" />
    </x-ui.stat-row>

    @if ($expenses->isEmpty())
        <x-ui.empty-state
            title="No expenses logged"
            description="Expenses you log above will show up here."
        />
    @else
        <x-ui.table stack :headers="['Description', 'Date', 'Amount', 'Status', '']">
            @foreach ($expenses as $expense)
                <x-ui.table-row wire:key="expense-{{ $expense->id }}" @class(['opacity-50' => $expense->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold text-ink whitespace-normal">{{ $expense->description }}</x-ui.table-cell>
                    <x-ui.table-cell label="Date">{{ $expense->expense_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Amount" class="font-semibold text-ink">Rs {{ number_format($expense->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        @if ($expense->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">Active</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        @if ($expense->employee_advance_id !== null)
                            <span class="text-xs text-ink-faint">Advance written off</span>
                        @elseif ($expense->payslip_id !== null)
                            <span class="text-xs text-ink-faint">From Payroll</span>
                        @elseif (! $expense->isCancelled() && auth()->user()->hasAccessTo('expenses-edit-cancel'))
                            <x-ui.row-actions label="Actions for {{ $expense->description }}">
                                <x-ui.menu-item wire:click="openEdit({{ $expense->id }})">Edit</x-ui.menu-item>
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="openCancel({{ $expense->id }})">Delete</x-ui.menu-item>
                            </x-ui.row-actions>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $expenses->links() }}
        </div>
    @endif

    <x-ui.modal name="edit-expense-form" max-width="sm">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-bold text-ink">Edit Expense</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Description" name="editDescription" for="editDescription" required>
                    <x-ui.input wire:model="editDescription" id="editDescription" autofocus />
                </x-ui.field>

                <x-ui.field label="Amount" name="editAmount" for="editAmount" required>
                    <x-ui.input wire:model="editAmount" id="editAmount" type="number" min="0.01" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Date" name="editExpenseDate" for="editExpenseDate" required>
                    <x-ui.input wire:model="editExpenseDate" id="editExpenseDate" type="date" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEditForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEdit">
                    Save Changes
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-expense-form" max-width="sm">
        <form wire:submit="cancelExpense" class="p-6">
            <h2 class="text-lg font-bold text-ink">Cancel This Expense?</h2>
            <p class="mt-2 text-sm text-ink-muted">
                This expense will be marked <strong>Cancelled</strong> and will no longer count toward the total. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Expense
                </x-ui.button>

                <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelExpense">
                    Cancel Expense
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
