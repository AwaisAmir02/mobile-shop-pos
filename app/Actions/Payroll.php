<?php

namespace App\Actions;

use App\Enums\AdvanceType;
use App\Enums\PayslipStatus;
use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Enums\SalaryType;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\ModuleRevision;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to pay goes through here. A payslip is a Draft (fully editable),
 * then Paid (adds one linked Expense and recovers any advance), then possibly
 * Cancelled (undoes both, keeps a revision). Nothing else writes these rows.
 */
class Payroll
{
    /**
     * Drafts for everyone active who doesn't already have a payslip this month.
     *
     * @return int how many were created
     */
    public static function generate(int $shopId, string $period, ?int $branchId, ?int $userId): int
    {
        self::assertEnabled($shopId);

        $month = self::month($period);
        $created = 0;

        $employees = Employee::where('shop_id', $shopId)
            ->where('is_active', true)
            ->inBranchOrShared()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();

        $defaults = DeductionType::where('shop_id', $shopId)->where('is_active', true)->whereNotNull('default_amount')->where('default_amount', '>', 0)->get();

        foreach ($employees as $employee) {
            $created += DB::transaction(function () use ($employee, $month, $defaults, $userId) {
                $employee = Employee::whereKey($employee->id)->lockForUpdate()->first();

                if (Payslip::where('employee_id', $employee->id)->whereDate('period', $month)->exists()) {
                    return 0;
                }

                $payslip = new Payslip([
                    'employee_id' => $employee->id,
                    'user_id' => $userId,
                    'period' => $month,
                    'employee_name' => $employee->name,
                    'designation' => $employee->designation,
                    'salary_type' => $employee->salary_type,
                    'rate' => $employee->rate,
                ]);
                $payslip->shop_id = $employee->shop_id;
                $payslip->branch_id = $employee->branch_id;
                $payslip->save();

                foreach ($defaults as $type) {
                    $payslip->lines()->create(['shop_id' => $payslip->shop_id, 'kind' => PayslipLine::DEDUCTION, 'label' => $type->name, 'amount' => $type->default_amount, 'deduction_type_id' => $type->id, 'reduces_cost' => $type->reduces_cost]);
                }

                $payslip->load('lines');
                $payslip->recalculate($payslip->lines);

                // A suggestion towards the balance; the owner can change it.
                $payslip->advance_recovery = max(0, min($employee->advanceBalance(), (float) $payslip->net_pay));
                $payslip->recalculate($payslip->lines);
                $payslip->save();

                return 1;
            });
        }

        return $created;
    }

    /**
     * @param  array{rate: mixed, days: mixed, advance_recovery: mixed, lines: array<int, array{kind: string, label?: string, amount: mixed, deduction_type_id?: ?int}>}  $fields
     */
    public static function saveDraft(Payslip $payslip, array $fields): Payslip
    {
        return DB::transaction(function () use ($payslip, $fields) {
            $payslip = Payslip::whereKey($payslip->id)->lockForUpdate()->firstOrFail();

            self::assertEnabled($payslip->shop_id);

            if (! $payslip->isDraft()) {
                throw ValidationException::withMessages(['payslip' => 'Only a draft can be edited. Cancel this payslip and generate it again.']);
            }

            $lines = [];

            foreach ($fields['lines'] as $line) {
                $amount = round((float) ($line['amount'] ?? 0), 2);

                if ($amount <= 0) {
                    throw ValidationException::withMessages(['payslip' => 'Each extra earning and deduction needs an amount above zero.']);
                }

                if ($line['kind'] === PayslipLine::DEDUCTION) {
                    $type = DeductionType::where('shop_id', $payslip->shop_id)->find($line['deduction_type_id'] ?? 0);

                    if ($type === null) {
                        throw ValidationException::withMessages(['payslip' => 'Choose a type for each deduction.']);
                    }

                    $lines[] = new PayslipLine(['kind' => PayslipLine::DEDUCTION, 'label' => $type->name, 'amount' => $amount, 'deduction_type_id' => $type->id, 'reduces_cost' => $type->reduces_cost]);
                } else {
                    $label = trim((string) ($line['label'] ?? ''));

                    if ($label === '') {
                        throw ValidationException::withMessages(['payslip' => 'Give each extra earning a name, like Bonus or Commission.']);
                    }

                    $lines[] = new PayslipLine(['kind' => PayslipLine::EARNING, 'label' => mb_substr($label, 0, 100), 'amount' => $amount, 'reduces_cost' => false]);
                }
            }

            $payslip->rate = max(0, round((float) $fields['rate'], 2));
            $payslip->days = $payslip->salary_type === SalaryType::Daily ? max(0, round((float) ($fields['days'] ?? 0), 2)) : null;
            $payslip->advance_recovery = max(0, round((float) ($fields['advance_recovery'] ?? 0), 2));
            $payslip->recalculate($lines);

            self::assertPayable($payslip, Employee::whereKey($payslip->employee_id)->lockForUpdate()->firstOrFail());

            $payslip->save();
            $payslip->lines()->delete();

            foreach ($lines as $line) {
                $line->shop_id = $payslip->shop_id;
                $payslip->lines()->save($line);
            }

            return $payslip->fresh('lines');
        });
    }

    /** Records one linked Expense and the advance recovery, once. */
    public static function pay(Payslip $payslip, string $paidOn, ?int $shopAccountId, ?int $userId): Payslip
    {
        return BranchContext::current()->shopWide(fn () => DB::transaction(function () use ($payslip, $paidOn, $shopAccountId, $userId) {
            $payslip = Payslip::whereKey($payslip->id)->with('lines')->lockForUpdate()->firstOrFail();

            self::assertEnabled($payslip->shop_id);

            if (! $payslip->isDraft()) {
                throw ValidationException::withMessages(['payslip' => $payslip->number().' is already '.strtolower($payslip->status->label()).'.']);
            }

            $employee = Employee::whereKey($payslip->employee_id)->lockForUpdate()->firstOrFail();

            $payslip->recalculate($payslip->lines);
            self::assertPayable($payslip, $employee);

            if ($payslip->grossEarnings() <= 0) {
                throw ValidationException::withMessages(['payslip' => "{$payslip->employee_name} has nothing to be paid. Enter the days worked or an amount first."]);
            }

            $payslip->fill([
                'status' => PayslipStatus::Paid,
                'paid_on' => $paidOn,
                'paid_by' => $userId,
                'shop_account_id' => $shopAccountId,
            ])->save();

            if ((float) $payslip->advance_recovery > 0) {
                $employee->advances()->create([
                    'shop_id' => $payslip->shop_id,
                    'user_id' => $userId,
                    'payslip_id' => $payslip->id,
                    'type' => AdvanceType::Deducted,
                    'amount' => $payslip->advance_recovery,
                    'transaction_date' => $paidOn,
                    'note' => 'Deducted on '.$payslip->number(),
                ]);
            }

            // A pay cut to nothing by fines still has no cost to record.
            if ((float) $payslip->salary_cost > 0) {
                $expense = new Expense([
                    'user_id' => $userId,
                    'payslip_id' => $payslip->id,
                    'description' => 'Salary: '.$payslip->employee_name.', '.$payslip->period->format('M Y'),
                    'amount' => $payslip->salary_cost,
                    'expense_date' => $paidOn,
                ]);
                $expense->shop_id = $payslip->shop_id;
                // Their branch's comparison carries the cost; a shop-wide person goes to Main.
                $expense->branch_id = $payslip->branch_id ?? BranchContext::current()->mainBranchIdFor($payslip->shop_id);
                $expense->save();
            }

            return $payslip->fresh(['lines', 'expense']);
        }));
    }

    /** Undoes everything paying did, and frees the month for a new payslip. */
    public static function cancel(Payslip $payslip, ?int $userId, ?string $reason = null): Payslip
    {
        return BranchContext::current()->shopWide(fn () => DB::transaction(function () use ($payslip, $userId, $reason) {
            $payslip = Payslip::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($payslip->id)->with('lines')->lockForUpdate()->firstOrFail();

            if ($payslip->isCancelled()) {
                throw new \RuntimeException('This payslip has already been cancelled.');
            }

            $snapshot = $payslip->only(['employee_id', 'employee_name', 'salary_type', 'days', 'base_pay', 'extra_earnings', 'cost_deductions', 'held_deductions', 'advance_recovery', 'salary_cost', 'net_pay'])
                + ['status' => $payslip->status->value, 'rate' => (float) $payslip->rate, 'period' => $payslip->period->toDateString(), 'paid_on' => $payslip->paid_on?->toDateString(), 'lines' => $payslip->lines->map(fn ($line) => $line->only(['kind', 'label', 'amount', 'reduces_cost']))->all()];

            $expense = Expense::where('payslip_id', $payslip->id)->first();

            if ($expense !== null) {
                CancelExpense::handle($expense, $userId, $reason, fromPayslip: true);
            }

            EmployeeAdvance::where('payslip_id', $payslip->id)->get()->each(fn (EmployeeAdvance $row) => $row->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => 'Payslip '.$payslip->number().' cancelled',
            ]));

            $payslip->update([
                'status' => PayslipStatus::Cancelled,
                'active_marker' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $payslip->shop_id,
                'revisionable_type' => Payslip::class,
                'revisionable_id' => $payslip->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $payslip;
        }));
    }

    public static function recordAdvance(Employee $employee, AdvanceType $type, float $amount, string $date, ?string $note, ?int $shopAccountId, ?int $userId): EmployeeAdvance
    {
        if ($type === AdvanceType::Deducted || $type === AdvanceType::WrittenOff) {
            throw new \LogicException('Deductions only come from paying a payslip, and write-offs have their own action.');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['advanceAmount' => 'Enter an amount above zero.']);
        }

        return DB::transaction(function () use ($employee, $type, $amount, $date, $note, $shopAccountId, $userId) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($type === AdvanceType::Repaid && $amount > $employee->advanceBalance()) {
                throw ValidationException::withMessages(['advanceAmount' => "{$employee->name} only owes Rs ".number_format($employee->advanceBalance(), 2).'.']);
            }

            return $employee->advances()->create([
                'shop_id' => $employee->shop_id,
                'user_id' => $userId,
                'shop_account_id' => $shopAccountId,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $date,
                'note' => filled($note) ? $note : null,
            ]);
        });
    }

    /**
     * Stop expecting an advance back. It costs the shop, so it reaches Net through
     * a linked Expense. No cash moves, so that Expense doesn't post to the ledger;
     * this row does. Only with the ledger on: otherwise a normal Expense does the job.
     */
    public static function writeOffAdvance(Employee $employee, float $amount, string $date, ?string $note, ?int $userId): EmployeeAdvance
    {
        OwnerMoney::assertLedgerOn(Shop::findOrFail($employee->shop_id));

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['advanceAmount' => 'Enter an amount above zero.']);
        }

        return DB::transaction(function () use ($employee, $amount, $date, $note, $userId) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($amount > $employee->advanceBalance()) {
                throw ValidationException::withMessages(['advanceAmount' => "{$employee->name} only owes Rs ".number_format($employee->advanceBalance(), 2).'.']);
            }

            $row = $employee->advances()->create([
                'shop_id' => $employee->shop_id,
                'user_id' => $userId,
                'type' => AdvanceType::WrittenOff,
                'amount' => $amount,
                'transaction_date' => $date,
                'note' => filled($note) ? $note : null,
            ]);

            $expense = new Expense([
                'user_id' => $userId,
                'employee_advance_id' => $row->id,
                'description' => 'Advance written off: '.$employee->name,
                'amount' => $amount,
                'expense_date' => $date,
            ]);
            $expense->shop_id = $employee->shop_id;
            $expense->branch_id = $employee->branch_id ?? BranchContext::current()->mainBranchIdFor($employee->shop_id);
            $expense->save();

            return $row;
        });
    }

    public static function cancelAdvance(EmployeeAdvance $row, ?int $userId, ?string $reason = null): void
    {
        DB::transaction(function () use ($row, $userId, $reason) {
            $row = EmployeeAdvance::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::whereKey($row->employee_id)->lockForUpdate()->firstOrFail();

            if ($row->isCancelled()) {
                throw new \RuntimeException('This entry has already been cancelled.');
            }

            if ($row->payslip_id !== null) {
                throw ValidationException::withMessages(['advance' => 'This came from a payslip. Cancel the payslip instead.']);
            }

            if ($row->type === AdvanceType::Given && $employee->advanceBalance() < (float) $row->amount) {
                throw ValidationException::withMessages(['advance' => 'Part of this advance has already been repaid, so it can\'t be cancelled.']);
            }

            if ($row->type === AdvanceType::WrittenOff) {
                BranchContext::current()->shopWide(function () use ($row, $userId, $reason) {
                    $expense = Expense::where('employee_advance_id', $row->id)->first();

                    if ($expense !== null) {
                        CancelExpense::handle($expense, $userId, $reason, fromPayslip: true);
                    }
                });
            }

            $snapshot = ['type' => $row->type->value, 'amount' => (float) $row->amount, 'transaction_date' => $row->transaction_date->toDateString(), 'note' => $row->note];

            $row->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancel_reason' => filled($reason) ? $reason : null]);

            ModuleRevision::create([
                'shop_id' => $row->shop_id,
                'revisionable_type' => EmployeeAdvance::class,
                'revisionable_id' => $row->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);
        });
    }

    protected static function assertPayable(Payslip $payslip, Employee $employee): void
    {
        $balance = $employee->advanceBalance();

        if ((float) $payslip->advance_recovery > $balance) {
            throw ValidationException::withMessages(['payslip' => "{$payslip->employee_name} only owes Rs ".number_format($balance, 2).' in advances.']);
        }

        if ((float) $payslip->net_pay < 0 || (float) $payslip->salary_cost < 0) {
            throw ValidationException::withMessages(['payslip' => "{$payslip->employee_name}'s deductions come to more than their pay."]);
        }
    }

    /** History stays readable after a downgrade, but nothing new can be run. */
    public static function assertEnabled(int $shopId): void
    {
        if (! Shop::whereKey($shopId)->value('payroll_enabled')) {
            throw ValidationException::withMessages(['payslip' => 'Payroll isn\'t on your plan right now. Your history is still here, but new payroll can\'t be run.']);
        }
    }

    protected static function month(string $period): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            throw ValidationException::withMessages(['period' => 'Choose a month.']);
        }

        return Carbon::parse($period.'-01')->toDateString();
    }
}
