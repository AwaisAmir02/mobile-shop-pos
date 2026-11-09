<?php

use App\Actions\Payroll;
use App\Enums\AdvanceType;
use App\Livewire\Concerns\Toasts;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\ShopAccount;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Salary Advances')] class extends Component
{
    use Toasts;

    /** Checked on every action too: the route middleware only runs on the first load. */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('payroll'), 403);
    }

    #[Url]
    public string $employee = '';

    public string $advanceType = 'given';
    public string $advanceAmount = '';
    public string $advanceDate = '';
    public string $advanceNote = '';
    public string $advanceAccountId = '';

    public ?int $cancelingId = null;

    public function with(): array
    {
        $employees = Employee::query()->inBranchOrShared()->orderByDesc('is_active')->orderBy('name')->get();
        $selected = $employees->firstWhere('id', (int) $this->employee);

        return [
            'employees' => $employees,
            'selected' => $selected,
            'balance' => $selected?->advanceBalance(),
            'rows' => $selected ? EmployeeAdvance::withoutGlobalScope(ExcludesCancelledScope::class)->where('employee_id', $selected->id)->with(['user', 'payslip'])->latest('transaction_date')->latest('id')->get() : collect(),
            'owing' => $employees->map(fn (Employee $person) => ['employee' => $person, 'balance' => $person->advanceBalance()])->filter(fn (array $row) => $row['balance'] > 0)->values(),
            'accounts' => ShopAccount::query()->orderBy('name')->get(),
        ];
    }

    public function openForm(string $type): void
    {
        abort_unless(in_array($type, ['given', 'repaid', 'written_off'], true), 404);

        $this->advanceType = $type;
        $this->advanceAmount = '';
        $this->advanceNote = '';
        $this->advanceAccountId = '';
        $this->advanceDate = BatchExpiry::today();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'advance-form');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'advance-form');
    }

    public function save(): void
    {
        $person = Employee::query()->inBranchOrShared()->findOrFail((int) $this->employee);

        $this->validate([
            'advanceType' => ['required', Rule::in(['given', 'repaid', 'written_off'])],
            'advanceAmount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'advanceDate' => ['required', 'date', 'before_or_equal:'.BatchExpiry::today()],
            'advanceNote' => ['nullable', 'string', 'max:255'],
            'advanceAccountId' => ['nullable', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
        ], [], ['advanceAmount' => 'amount', 'advanceDate' => 'date']);

        if ($this->advanceType === 'written_off') {
            Payroll::writeOffAdvance($person, (float) $this->advanceAmount, $this->advanceDate, $this->advanceNote, Auth::id());
            $this->toastSuccess('Advance written off and added to Expenses.');
        } else {
            Payroll::recordAdvance($person, AdvanceType::from($this->advanceType), (float) $this->advanceAmount, $this->advanceDate, $this->advanceNote, $this->advanceAccountId !== '' ? (int) $this->advanceAccountId : null, Auth::id());
            $this->toastSuccess($this->advanceType === 'given' ? 'Advance recorded.' : 'Repayment recorded.');
        }

        $this->closeForm();
    }

    public function cancelRow(int $id): void
    {
        $row = EmployeeAdvance::whereIn('employee_id', Employee::query()->inBranchOrShared()->select('id'))->findOrFail($id);

        Payroll::cancelAdvance($row, Auth::id());
        $this->toastSuccess('Entry cancelled.');
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Salary Advances</x-ui.page-header>
    </x-slot>

    <x-payroll.nav active="payroll.advances" />

    <x-ui.note>An advance is money owed back to the shop, so it doesn't appear in Expenses. When someone leaves owing an advance you won't recover, add it to Expenses yourself.</x-ui.note>

    <x-ui.error :messages="$errors->get('advance')" class="mb-4" />

    <x-ui.filter-bar :active="(int) filled($employee)">
        <x-ui.field label="Employee" name="employee" for="advanceEmployee" class="w-full sm:w-64">
            <x-ui.select wire:model.live="employee" id="advanceEmployee">
                <option value="">Everyone owing</option>
                @foreach ($employees as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        @if ($selected)
            <x-slot:actions>
                <x-ui.button size="sm" wire:click="openForm('given')">Give Advance</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="openForm('repaid')">Record Repayment</x-ui.button>
                @if (auth()->user()->shop?->ledger_ready_at && ! auth()->user()->shop?->ledger_frozen_at && $balance > 0)
                    <x-ui.button size="sm" variant="ghost" wire:click="openForm('written_off')">Write Off</x-ui.button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.filter-bar>

    @if ($selected)
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat label="Currently Owes" value="Rs {{ number_format($balance, 2) }}" :sub="$selected->name" />
        </div>

        @if ($rows->isEmpty())
            <x-ui.empty-state title="No advances" description="Advances given to {{ $selected->name }} show up here." />
        @else
            <x-ui.table stack :headers="['Date', 'Entry', 'Amount', 'Note', 'By', '']">
                @foreach ($rows as $row)
                    <x-ui.table-row wire:key="advance-{{ $row->id }}" @class(['opacity-50' => $row->isCancelled()])>
                        <x-ui.table-cell primary>{{ $row->transaction_date->format('d M Y') }}</x-ui.table-cell>
                        <x-ui.table-cell label="Entry">{{ $row->type->label() }}</x-ui.table-cell>
                        <x-ui.table-cell label="Amount" class="font-medium text-ink">{{ $row->type->sign() > 0 ? '+' : '−' }}Rs {{ number_format($row->amount, 2) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Note">{{ $row->note ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="By">{{ $row->user?->name ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell actions align="right">
                            @if ($row->isCancelled())
                                <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                            @elseif ($row->payslip_id === null)
                                <x-ui.button size="sm" variant="danger-ghost" wire:click="cancelRow({{ $row->id }})" confirm="Cancel this entry?">Cancel</x-ui.button>
                            @endif
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($owing->isEmpty())
        <x-ui.empty-state title="Nobody owes an advance" description="Pick an employee above to give one." />
    @else
        <x-ui.table stack :headers="['Employee', 'Owes']">
            @foreach ($owing as $row)
                <x-ui.table-row wire:key="owing-{{ $row['employee']->id }}">
                    <x-ui.table-cell primary class="font-medium text-ink">{{ $row['employee']->name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Owes">Rs {{ number_format($row['balance'], 2) }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="advance-form" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-ink">{{ ['given' => 'Give Advance', 'repaid' => 'Record Repayment', 'written_off' => 'Write Off Advance'][$advanceType] }}</h2>
            <p class="mt-1 text-sm text-ink-faint">{{ $selected?->name }}</p>

            <div class="mt-5 space-y-4">
                <x-ui.field label="Amount" name="advanceAmount" for="advanceAmount">
                    <x-ui.input wire:model="advanceAmount" id="advanceAmount" type="number" min="0.01" step="0.01" />
                </x-ui.field>
                <x-ui.field label="Date" name="advanceDate" for="advanceDate">
                    <x-ui.input wire:model="advanceDate" id="advanceDate" type="date" />
                </x-ui.field>
                @if ($advanceType === 'written_off')
                    <p class="text-sm text-ink-muted">You no longer expect this back. It's added to Expenses as a cost, and no cash moves.</p>
                @elseif ($accounts->isNotEmpty())
                    <x-ui.field :label="$advanceType === 'given' ? 'Paid From' : 'Received Into'" name="advanceAccountId" for="advanceAccountId">
                        <x-ui.select wire:model="advanceAccountId" id="advanceAccountId">
                            <option value="">Cash</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
                <x-ui.field label="Note" name="advanceNote" for="advanceNote">
                    <x-ui.input wire:model="advanceNote" id="advanceNote" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
