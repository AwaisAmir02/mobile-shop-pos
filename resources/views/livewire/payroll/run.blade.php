<?php

use App\Actions\Payroll;
use App\Enums\SalaryType;
use App\Livewire\Concerns\ShowsPayrollNotice;
use App\Livewire\Concerns\Toasts;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Scopes\BranchScope;
use App\Models\ShopAccount;
use App\Services\PayslipPdfService;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Run Payroll')] class extends Component
{
    use ShowsPayrollNotice, Toasts;

    /** Checked on every action too: the route middleware only runs on the first load. */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('payroll-run'), 403);
    }

    public string $period = '';
    public string $branchFilter = '';

    public ?int $editingId = null;
    public string $editRate = '';
    public string $editDays = '';
    public string $editAdvance = '';
    /** @var array<int, array{kind: string, label: string, amount: string, deduction_type_id: string}> */
    public array $editLines = [];
    public int $nextLine = 1;
    /** A daily wage is 0 until its days are typed, so the advance is suggested then. */
    public bool $suggestRecovery = false;

    public array $payingIds = [];
    public string $paidOn = '';
    public string $paidFromAccountId = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

    public function mount(): void
    {
        $this->period = Carbon::now('Asia/Karachi')->format('Y-m');
        $this->paidOn = BatchExpiry::today();
    }

    protected function monthStart(): string
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->period) === 1 ? $this->period.'-01' : now()->startOfMonth()->toDateString();
    }

    protected function payslips()
    {
        return Payslip::query()
            ->inBranchOrShared()
            ->whereDate('period', $this->monthStart())
            ->when($this->branchFilter, fn ($q) => $q->where('branch_id', $this->branchFilter))
            ->with(['lines', 'employee'])
            ->orderBy('employee_name')
            ->get();
    }

    /**
     * Best-effort: only the words can be searched, so this can miss a salary
     * entry or flag something unrelated. The screen says so.
     */
    protected function possibleManualSalaries()
    {
        $start = Carbon::parse($this->monthStart());

        // The run is shop-wide, so anyone who can see every branch is warned about all of them.
        return Expense::query()
            ->when(! BranchContext::current()->restrictedBranchId(), fn ($q) => $q->withoutGlobalScope(BranchScope::class))
            ->whereNull('payslip_id')
            ->whereBetween('expense_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->where(fn ($q) => $q->whereRaw('LOWER(description) LIKE ?', ['%salar%'])->orWhereRaw('LOWER(description) LIKE ?', ['%wage%']))
            ->latest('expense_date')
            ->limit(10)
            ->get();
    }

    public function with(): array
    {
        $context = BranchContext::current();
        $payslips = $this->payslips();
        $editing = $this->editingId ? $payslips->firstWhere('id', $this->editingId) : null;

        return [
            'payslips' => $payslips,
            'editing' => $editing,
            'editPreview' => $editing ? $this->previewEdit($editing) : null,
            'editBalance' => $editing?->employee?->advanceBalance(),
            'draftIds' => $payslips->filter->isDraft()->pluck('id')->all(),
            'manualSalaries' => $this->possibleManualSalaries(),
            'showNotice' => $this->showPayrollNotice(),
            'enabled' => (bool) Auth::user()->shop?->payroll_enabled,
            'branches' => $context->restrictedBranchId() ? collect() : $context->branchesFor(Auth::user()->shop_id),
            'deductionTypes' => DeductionType::query()->where('is_active', true)->orderBy('name')->get(),
            'accounts' => ShopAccount::query()->orderBy('name')->get(),
            'missing' => Employee::query()->where('is_active', true)->inBranchOrShared()
                ->when($this->branchFilter, fn ($q) => $q->where('branch_id', $this->branchFilter))
                ->whereNotIn('id', $payslips->pluck('employee_id'))->count(),
        ];
    }

    public function generate(): void
    {
        $this->validate(['period' => ['required', 'date_format:Y-m']]);

        $count = Payroll::generate(Auth::user()->shop_id, $this->period, $this->branchFilter !== '' ? (int) $this->branchFilter : null, Auth::id());

        $count > 0 ? $this->toastSuccess($count.' draft '.str('payslip')->plural($count).' created.') : $this->toastError('Everyone active already has a payslip for this month.');
    }

    protected function findPayslip(int $id): Payslip
    {
        return Payslip::query()->inBranchOrShared()->findOrFail($id);
    }

    public function openEdit(int $id): void
    {
        $payslip = $this->findPayslip($id)->load('lines');

        $this->editingId = $payslip->id;
        $this->editRate = (string) $payslip->rate;
        $this->editDays = $payslip->days !== null ? (string) (float) $payslip->days : '';
        $this->editAdvance = (string) $payslip->advance_recovery;
        $this->suggestRecovery = $payslip->days === null && (float) $payslip->advance_recovery === 0.0;
        $this->editLines = [];
        $this->nextLine = 1;

        foreach ($payslip->lines as $line) {
            $this->editLines[$this->nextLine++] = ['kind' => $line->kind, 'label' => $line->label, 'amount' => (string) $line->amount, 'deduction_type_id' => (string) ($line->deduction_type_id ?? '')];
        }

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'payslip-form');
    }

    public function addLine(string $kind): void
    {
        abort_unless(in_array($kind, [PayslipLine::EARNING, PayslipLine::DEDUCTION], true), 404);

        $this->editLines[$this->nextLine++] = ['kind' => $kind, 'label' => '', 'amount' => '', 'deduction_type_id' => ''];
    }

    public function removeLine(int $key): void
    {
        unset($this->editLines[$key]);
    }

    public function updatedEditDays(): void
    {
        if (! $this->suggestRecovery) {
            return;
        }

        $payslip = $this->findPayslip($this->editingId)->load('employee');
        $this->editAdvance = '0';
        $this->editAdvance = (string) max(0, min($payslip->employee->advanceBalance(), $this->previewEdit($payslip)['net']));
    }

    /** The owner has decided, so stop suggesting. */
    public function updatedEditAdvance(): void
    {
        $this->suggestRecovery = false;
    }

    public function updatedEditLines(mixed $value, string $key): void
    {
        [$row, $field] = explode('.', $key);

        if ($field === 'deduction_type_id' && is_numeric($value) && ($this->editLines[$row]['amount'] ?? '') === '') {
            $this->editLines[$row]['amount'] = (string) (DeductionType::find($value)?->default_amount ?? '');
        }
    }

    /** @return array{gross: float, deductions: float, net: float} */
    protected function previewEdit(Payslip $payslip): array
    {
        $rate = (float) $this->editRate;
        $base = $payslip->salary_type === SalaryType::Daily ? Quantity::money($rate, is_numeric($this->editDays) ? $this->editDays : 0) : $rate;
        $lines = collect($this->editLines);
        $gross = round($base + $lines->where('kind', PayslipLine::EARNING)->sum(fn ($line) => (float) $line['amount']), 2);
        $deductions = round($lines->where('kind', PayslipLine::DEDUCTION)->sum(fn ($line) => (float) $line['amount']), 2);

        return ['gross' => $gross, 'deductions' => $deductions, 'net' => round($gross - $deductions - (float) $this->editAdvance, 2)];
    }

    public function saveEdit(): void
    {
        $payslip = $this->findPayslip($this->editingId);

        $this->validate([
            'editRate' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'editDays' => [$payslip->salary_type === SalaryType::Daily ? 'required' : 'nullable', 'numeric', 'min:0', 'max:31'],
            'editAdvance' => ['nullable', 'numeric', 'min:0'],
        ], [], ['editRate' => 'rate', 'editDays' => 'days', 'editAdvance' => 'advance recovery']);

        Payroll::saveDraft($payslip, [
            'rate' => $this->editRate,
            'days' => $this->editDays,
            'advance_recovery' => $this->editAdvance,
            'lines' => array_values($this->editLines),
        ]);

        $this->toastSuccess('Payslip saved.');
        $this->closeEdit();
    }

    public function closeEdit(): void
    {
        $this->dispatch('close-modal', name: 'payslip-form');
        $this->reset(['editingId', 'editRate', 'editDays', 'editAdvance', 'editLines']);
    }

    public function openPay(?int $id = null): void
    {
        $this->payingIds = $id !== null ? [$this->findPayslip($id)->id] : $this->payslips()->filter->isDraft()->pluck('id')->all();
        $this->paidOn = BatchExpiry::today();
        $this->paidFromAccountId = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'payslip-pay-form');
    }

    public function closePay(): void
    {
        $this->dispatch('close-modal', name: 'payslip-pay-form');
        $this->payingIds = [];
    }

    /** Each is paid on its own, so one problem doesn't stop the rest. */
    public function pay(): void
    {
        $this->validate([
            'paidOn' => ['required', 'date', 'before_or_equal:'.BatchExpiry::today()],
            'paidFromAccountId' => ['nullable', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
        ], [], ['paidOn' => 'paid date']);

        $paid = 0;
        $problems = [];

        foreach ($this->payingIds as $id) {
            try {
                Payroll::pay($this->findPayslip((int) $id), $this->paidOn, $this->paidFromAccountId !== '' ? (int) $this->paidFromAccountId : null, Auth::id());
                $paid++;
            } catch (ValidationException $e) {
                $problems[] = collect($e->errors())->flatten()->first();
            }
        }

        $this->closePay();

        if ($paid > 0) {
            $this->toastSuccess($paid.' '.str('payslip')->plural($paid).' paid and added to Expenses.');
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['payslip' => $problems]);
        }
    }

    public function openCancel(int $id): void
    {
        $this->cancelingId = $this->findPayslip($id)->id;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'payslip-cancel-form');
    }

    public function closeCancel(): void
    {
        $this->dispatch('close-modal', name: 'payslip-cancel-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }

    public function cancelPayslip(): void
    {
        Payroll::cancel($this->findPayslip($this->cancelingId), Auth::id(), $this->cancelReason);

        $this->closeCancel();
        $this->toastSuccess('Payslip cancelled. Its expense and any advance deduction were reversed.');
    }

    public function downloadPdf(int $id, PayslipPdfService $pdf): StreamedResponse
    {
        return $pdf->download($this->findPayslip($id));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Run Payroll</x-ui.page-header>
    </x-slot>

    <x-payroll.nav active="payroll.run" />

    @if ($showNotice)
        <x-payroll.first-use-notice />
    @endif

    @if ($manualSalaries->isNotEmpty())
        <div class="mb-4 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm text-warning">
            <p class="font-semibold">These expenses this month might already be salaries</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($manualSalaries as $expense)
                    <li>{{ $expense->expense_date->format('d M') }}: {{ $expense->description }}, Rs {{ number_format($expense->amount, 2) }}</li>
                @endforeach
            </ul>
            <p class="mt-1">Paying the same salary here too would count it twice in Net (Revenue − Expenses). This only looks for words like "salary" and "wages", so it can miss entries or flag unrelated ones. Please check yourself.</p>
        </div>
    @endif

    <x-ui.error :messages="$errors->get('payslip')" class="mb-4" />

    <x-ui.filter-bar :active="(int) filled($branchFilter)">
            <x-ui.field label="Month" name="period" for="payrollPeriod" class="w-full sm:w-48">
                <x-ui.input wire:model.live="period" id="payrollPeriod" type="month" />
            </x-ui.field>
            @if ($branches->count() > 1)
                <x-ui.field label="Branch" name="branchFilter" for="payrollBranch" class="w-full sm:w-48">
                    <x-ui.select wire:model.live="branchFilter" id="payrollBranch">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif

        @if ($enabled)
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" :disabled="$missing === 0">Generate Drafts{{ $missing > 0 ? ' ('.$missing.')' : '' }}</x-ui.button>
                @if ($draftIds !== [])
                    <x-ui.button type="button" wire:click="openPay">Pay All Drafts ({{ count($draftIds) }})</x-ui.button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.filter-bar>

    @if ($payslips->isEmpty())
        <x-ui.empty-state title="No payslips for this month" description="Generate drafts for everyone active, then check them and mark them paid." />
    @else
        <x-ui.table stack :headers="['Employee', 'Earnings', 'Deductions', 'Advance', 'Net Pay', 'Status', '']">
            @foreach ($payslips as $payslip)
                <x-ui.table-row wire:key="payslip-{{ $payslip->id }}">
                    <x-ui.table-cell primary class="font-medium text-ink">{{ $payslip->employee_name }}
                        <p class="text-xs font-normal text-ink-faint">
                            {{ $payslip->number() }} · Rs {{ number_format($payslip->rate, 2) }}
                            @if ($payslip->salary_type === SalaryType::Daily)
                                <span class="{{ $payslip->days === null ? 'text-warning' : '' }}">× {{ $payslip->days === null ? 'days needed' : (float) $payslip->days.' days' }}</span>
                            @endif
                        </p>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Earnings">Rs {{ number_format($payslip->grossEarnings(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Deductions">Rs {{ number_format((float) $payslip->cost_deductions + (float) $payslip->held_deductions, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Advance">Rs {{ number_format($payslip->advance_recovery, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Net Pay" class="font-semibold text-ink">{{ $payslip->salary_type === SalaryType::Daily && $payslip->days === null ? '—' : 'Rs '.number_format($payslip->net_pay, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status"><x-ui.badge :variant="$payslip->status->badge()">{{ $payslip->status->label() }}</x-ui.badge></x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $payslip->employee_name }}">
                            @if ($payslip->isDraft() && $enabled)
                                <x-slot:primary>
                                    <x-ui.button size="sm" variant="secondary" wire:click="openPay({{ $payslip->id }})">Mark Paid</x-ui.button>
                                </x-slot:primary>
                                <x-ui.menu-item wire:click="openEdit({{ $payslip->id }})">Edit</x-ui.menu-item>
                            @endif
                            <x-ui.menu-item wire:click="downloadPdf({{ $payslip->id }})">Download PDF</x-ui.menu-item>
                            <x-ui.menu-divider />
                            <x-ui.menu-item danger wire:click="openCancel({{ $payslip->id }})">Cancel</x-ui.menu-item>
                        </x-ui.row-actions>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="payslip-form" max-width="2xl">
        <form wire:submit="saveEdit" class="p-6">
            @if ($editing)
                <h2 class="text-lg font-semibold text-ink">{{ $editing->employee_name }}: {{ $editing->period->format('F Y') }}</h2>

                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field :label="$editing->salary_type->rateLabel()" name="editRate" for="editRate">
                        <x-ui.input wire:model.live.debounce.400ms="editRate" id="editRate" type="number" min="0" step="0.01" />
                    </x-ui.field>
                    @if ($editing->salary_type === SalaryType::Daily)
                        <x-ui.field label="Days Worked" name="editDays" for="editDays">
                            <x-ui.input wire:model.live.debounce.400ms="editDays" id="editDays" type="number" min="0" max="31" step="0.5" />
                        </x-ui.field>
                    @endif
                    <x-ui.field label="Advance Recovery" name="editAdvance" for="editAdvance" :help="'Owes Rs '.number_format($editBalance ?? 0, 2)">
                        <x-ui.input wire:model.live.debounce.400ms="editAdvance" id="editAdvance" type="number" min="0" step="0.01" />
                    </x-ui.field>
                </div>

                <div class="mt-5 space-y-3">
                    @foreach ($editLines as $key => $line)
                        <div class="grid grid-cols-[minmax(0,1fr)_8rem_auto] items-start gap-2" wire:key="edit-line-{{ $key }}">
                            @if ($line['kind'] === 'earning')
                                <x-ui.input wire:model="editLines.{{ $key }}.label" placeholder="Extra earning, like Bonus or Commission" aria-label="Earning name" />
                            @else
                                <x-ui.select wire:model.live="editLines.{{ $key }}.deduction_type_id" aria-label="Deduction type">
                                    <option value="">Choose a deduction</option>
                                    @foreach ($deductionTypes as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif
                            <x-ui.input wire:model.live.debounce.400ms="editLines.{{ $key }}.amount" type="number" min="0" step="0.01" aria-label="Amount" wire:key="edit-line-amount-{{ $key }}-{{ $line['deduction_type_id'] }}" />
                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="removeLine({{ $key }})">Remove</x-ui.button>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="addLine('earning')">Add Earning</x-ui.button>
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="addLine('deduction')" :disabled="$deductionTypes->isEmpty()">Add Deduction</x-ui.button>
                    @if ($deductionTypes->isEmpty())
                        <a href="{{ route('payroll.deduction-types') }}" wire:navigate class="self-center text-sm font-medium text-link hover:underline">Set up deduction types →</a>
                    @endif
                </div>

                <x-ui.error :messages="$errors->get('payslip')" class="mt-3" />

                <dl class="mt-5 space-y-1 rounded-lg bg-surface-sunken px-4 py-3 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Earnings</dt><dd>Rs {{ number_format($editPreview['gross'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Deductions</dt><dd>Rs {{ number_format($editPreview['deductions'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Advance recovery</dt><dd>Rs {{ number_format((float) $editAdvance, 2) }}</dd></div>
                    <div class="flex justify-between font-semibold text-ink"><dt>Net pay</dt><dd>Rs {{ number_format($editPreview['net'], 2) }}</dd></div>
                </dl>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" wire:click="closeEdit">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEdit">Save Draft</x-ui.button>
                </div>
            @endif
        </form>
    </x-ui.modal>

    <x-ui.modal name="payslip-pay-form" max-width="sm">
        <form wire:submit="pay" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Mark {{ count($payingIds) === 1 ? 'Payslip' : count($payingIds).' Payslips' }} Paid</h2>
            <p class="mt-1 text-sm text-ink-faint">Each adds its salary cost to Expenses on this date, and recovers any advance on it.</p>

            <div class="mt-5 space-y-4">
                <x-ui.field label="Paid On" name="paidOn" for="paidOn">
                    <x-ui.input wire:model="paidOn" id="paidOn" type="date" />
                </x-ui.field>
                @if ($accounts->isNotEmpty())
                    <x-ui.field label="Paid From" name="paidFromAccountId" for="paidFromAccountId">
                        <x-ui.select wire:model="paidFromAccountId" id="paidFromAccountId">
                            <option value="">Cash</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePay">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="pay">Mark Paid</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="payslip-cancel-form" max-width="sm">
        <form wire:submit="cancelPayslip" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This Payslip?</h2>
            <p class="mt-2 text-sm text-ink-muted">If it was paid, this also cancels its expense and puts any recovered advance back on what they owe. It doesn't record the money coming back. This cannot be undone.</p>
            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="payslipCancelReason">
                    <x-ui.input wire:model="cancelReason" id="payslipCancelReason" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancel">Keep It</x-ui.button>
                <x-ui.button variant="danger" type="submit" wire:loading.attr="disabled" wire:target="cancelPayslip">Cancel Payslip</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
