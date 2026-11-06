<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\Expense;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelExpense
{
    public static function handle(Expense $expense, ?int $userId, ?string $reason = null, bool $fromPayslip = false): Expense
    {
        return DB::transaction(function () use ($expense, $userId, $reason, $fromPayslip) {
            $expense = Expense::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->isCancelled()) {
                throw new \RuntimeException('This expense has already been cancelled.');
            }

            if (($expense->payslip_id !== null || $expense->employee_advance_id !== null) && ! $fromPayslip) {
                throw ValidationException::withMessages(['cancelReason' => 'This is a salary from Payroll. Cancel the payslip there instead.']);
            }

            $snapshot = [
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date->toDateString(),
            ];

            $expense->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $expense->shop_id,
                'revisionable_type' => Expense::class,
                'revisionable_id' => $expense->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $expense;
        });
    }
}
