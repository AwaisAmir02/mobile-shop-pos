<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\Expense;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;

class UpdateExpense
{
    public static function handle(Expense $expense, string $description, float $amount, string $expenseDate, ?int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $description, $amount, $expenseDate, $userId) {
            $expense = Expense::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->isCancelled()) {
                throw new \RuntimeException('A cancelled expense cannot be edited.');
            }

            $snapshot = [
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date->toDateString(),
            ];

            $expense->update([
                'description' => $description,
                'amount' => $amount,
                'expense_date' => $expenseDate,
            ]);

            ModuleRevision::create([
                'shop_id' => $expense->shop_id,
                'revisionable_type' => Expense::class,
                'revisionable_id' => $expense->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $expense;
        });
    }
}
