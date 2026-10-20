<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Enums\UdhaarTransactionType;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\UdhaarTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateUdhaarTransaction
{
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::in([UdhaarTransactionType::Given->value, UdhaarTransactionType::Repayment->value])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array{type: string, amount: float, transaction_date: string, note: ?string}  $fields
     */
    public static function handle(UdhaarTransaction $transaction, array $fields, ?int $userId): UdhaarTransaction
    {
        return DB::transaction(function () use ($transaction, $fields, $userId) {
            $transaction = UdhaarTransaction::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (! $transaction->isActionable()) {
                throw new \RuntimeException('This transaction can no longer be edited.');
            }

            $snapshot = [
                'type' => $transaction->type->value,
                'amount' => (float) $transaction->amount,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'note' => $transaction->note,
            ];

            $transaction->update([
                'type' => $fields['type'],
                'amount' => $fields['amount'],
                'transaction_date' => $fields['transaction_date'],
                'note' => $fields['note'] !== '' ? $fields['note'] : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $transaction->shop_id,
                'revisionable_type' => UdhaarTransaction::class,
                'revisionable_id' => $transaction->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $transaction;
        });
    }
}
