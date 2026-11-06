<?php

use App\Enums\SalaryType;
use App\Livewire\Concerns\ShowsPayrollNotice;
use App\Livewire\Concerns\Toasts;
use App\Models\Employee;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Employees')] class extends Component
{
    use ShowsPayrollNotice, Toasts;

    /** Checked on every action too: the route middleware only runs on the first load. */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('payroll'), 403);
    }

    public bool $showInactive = false;

    public int $formNonce = 0;
    public ?int $editingId = null;
    public string $name = '';
    public string $phone = '';
    public string $designation = '';
    public string $salaryType = 'fixed';
    public string $rate = '';
    public string $branchId = '';
    public string $userId = '';
    public string $joinedOn = '';
    public string $notes = '';

    public function with(): array
    {
        $shopId = Auth::user()->shop_id;
        $context = BranchContext::current();
        $employees = Employee::query()->inBranchOrShared()->with(['branch', 'user'])
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('name')->get();

        return [
            'employees' => $employees,
            'branches' => $context->restrictedBranchId() ? collect() : $context->branchesFor($shopId),
            'showBranch' => $context->hasMultipleBranches($shopId),
            'logins' => User::where('shop_id', $shopId)->orderBy('name')->get(),
            'showNotice' => $this->showPayrollNotice(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'employee-form');
    }

    public function openEdit(int $id): void
    {
        $employee = Employee::query()->inBranchOrShared()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->phone = (string) $employee->phone;
        $this->designation = (string) $employee->designation;
        $this->salaryType = $employee->salary_type->value;
        $this->rate = (string) $employee->rate;
        $this->branchId = (string) ($employee->branch_id ?? '');
        $this->userId = (string) ($employee->user_id ?? '');
        $this->joinedOn = $employee->joined_on?->toDateString() ?? '';
        $this->notes = (string) $employee->notes;
        $this->dispatch('open-modal', name: 'employee-form');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'employee-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'phone', 'designation', 'salaryType', 'rate', 'branchId', 'userId', 'joinedOn', 'notes']);
        $this->formNonce++;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $shopId = Auth::user()->shop_id;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'designation' => ['nullable', 'string', 'max:100'],
            'salaryType' => ['required', Rule::enum(SalaryType::class)],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('shop_id', $shopId)],
            'userId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('shop_id', $shopId), Rule::unique('employees', 'user_id')->ignore($this->editingId)],
            'joinedOn' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['userId.unique' => 'That login is already linked to another employee.'], ['userId' => 'login', 'salaryType' => 'salary type']);

        $employee = $this->editingId ? Employee::query()->inBranchOrShared()->findOrFail($this->editingId) : new Employee;

        $employee->fill([
            'name' => $this->name,
            'phone' => $this->phone !== '' ? $this->phone : null,
            'designation' => $this->designation !== '' ? $this->designation : null,
            'salary_type' => $this->salaryType,
            'rate' => $this->rate,
            'user_id' => $this->userId !== '' ? (int) $this->userId : null,
            'joined_on' => $this->joinedOn !== '' ? $this->joinedOn : null,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ]);

        // Branch-restricted staff can only keep people in their own branch.
        $restricted = BranchContext::current()->restrictedBranchId();
        $employee->branch_id = $restricted ?? ($this->branchId !== '' ? (int) $this->branchId : null);
        $employee->save();

        $this->toastSuccess($this->editingId ? 'Employee updated.' : 'Employee added.');
        $this->closeForm();
    }

    public function toggleActive(int $id): void
    {
        $employee = Employee::query()->inBranchOrShared()->findOrFail($id);
        $employee->update(['is_active' => ! $employee->is_active]);

        $this->toastSuccess($employee->is_active ? "{$employee->name} reactivated." : "{$employee->name} deactivated. Their history stays.");
    }

    public function delete(int $id): void
    {
        $employee = Employee::query()->inBranchOrShared()->findOrFail($id);

        if ($employee->hasPayrollHistory()) {
            $this->toastError("{$employee->name} has payroll history, so they can only be deactivated.");

            return;
        }

        $employee->delete();
        $this->toastSuccess('Employee removed.');
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Employees</h1>
            <x-ui.button size="sm" wire:click="openCreate">Add Employee</x-ui.button>
        </div>
    </x-slot>

    <x-payroll.nav active="payroll.employees" />

    @if ($showNotice)
        <x-payroll.first-use-notice />
    @endif

    <label class="mb-4 flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" wire:model.live="showInactive" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        Show deactivated
    </label>

    @if ($employees->isEmpty())
        <x-ui.empty-state title="No employees yet" description="Add everyone you pay, including people with no login, like a helper or delivery rider." />
    @else
        <x-ui.table :headers="['Name', 'Designation', 'Pay', ...($showBranch ? ['Branch'] : []), 'Login', 'Advance Owed', '']">
            @foreach ($employees as $employee)
                <x-ui.table-row wire:key="employee-{{ $employee->id }}" @class(['opacity-50' => ! $employee->is_active])>
                    <x-ui.table-cell class="font-medium text-slate-900">
                        {{ $employee->name }}
                        @unless ($employee->is_active)
                            <x-ui.badge>Deactivated</x-ui.badge>
                        @endunless
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $employee->designation ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($employee->rate, 2) }} <span class="text-xs text-slate-400">{{ $employee->salary_type === \App\Enums\SalaryType::Daily ? 'a day' : 'a month' }}</span></x-ui.table-cell>
                    @if ($showBranch)
                        <x-ui.table-cell>{{ $employee->branch?->name ?? 'Whole shop' }}</x-ui.table-cell>
                    @endif
                    <x-ui.table-cell>{{ $employee->user?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <a href="{{ route('payroll.advances', ['employee' => $employee->id]) }}" wire:navigate class="text-brand-700 hover:text-brand-800">Rs {{ number_format($employee->advanceBalance(), 2) }}</a>
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $employee->id }})">Edit</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $employee->id }})">{{ $employee->is_active ? 'Deactivate' : 'Reactivate' }}</x-ui.button>
                            @unless ($employee->hasPayrollHistory())
                                <x-ui.button size="sm" variant="ghost" wire:click="delete({{ $employee->id }})" wire:confirm="Remove {{ $employee->name }}?" class="text-red-600 hover:bg-red-50">Remove</x-ui.button>
                            @endunless
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="employee-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit Employee' : 'Add Employee' }}</h2>
            <p class="mt-1 text-sm text-slate-500">Changing the rate affects new payslips only.</p>

            <div class="mt-5 space-y-4" wire:key="employee-fields-{{ $formNonce }}">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Name" name="name" for="employeeName">
                        <x-ui.input wire:model="name" id="employeeName" />
                    </x-ui.field>
                    <x-ui.field label="Designation" name="designation" for="employeeDesignation" help="Optional">
                        <x-ui.input wire:model="designation" id="employeeDesignation" />
                    </x-ui.field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Salary Type" name="salaryType" for="employeeSalaryType">
                        <x-ui.select wire:model.live="salaryType" id="employeeSalaryType">
                            @foreach (SalaryType::cases() as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="SalaryType::from($salaryType)->rateLabel()" name="rate" for="employeeRate">
                        <x-ui.input wire:model="rate" id="employeeRate" type="number" min="0" step="0.01" />
                    </x-ui.field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @if ($branches->count() > 1)
                        <x-ui.field label="Branch" name="branchId" for="employeeBranch">
                            <x-ui.select wire:model="branchId" id="employeeBranch">
                                <option value="">Whole shop</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endif
                    <x-ui.field label="Login" name="userId" for="employeeLogin" help="Optional. Only if they also use the app">
                        <x-ui.select wire:model="userId" id="employeeLogin">
                            <option value="">No login</option>
                            @foreach ($logins as $login)
                                <option value="{{ $login->id }}">{{ $login->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Phone" name="phone" for="employeePhone" help="Optional">
                        <x-ui.input wire:model="phone" id="employeePhone" />
                    </x-ui.field>
                    <x-ui.field label="Joined" name="joinedOn" for="employeeJoined" help="Optional">
                        <x-ui.input wire:model="joinedOn" id="employeeJoined" type="date" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Notes" name="notes" for="employeeNotes" help="Optional">
                    <x-ui.input wire:model="notes" id="employeeNotes" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
