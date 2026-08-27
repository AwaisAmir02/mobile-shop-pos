<?php

namespace App\Actions;

use App\Enums\UdhaarTransactionType;
use App\Models\Customer;
use App\Models\UdhaarTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CreateUdhaarTransaction
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

    public static function handle(Customer $customer, string $type, string $amount, string $transactionDate, ?string $note = null): UdhaarTransaction
    {
        return UdhaarTransaction::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'type' => $type,
            'amount' => $amount,
            'transaction_date' => $transactionDate,
            'note' => $note !== null && $note !== '' ? $note : null,
        ]);
    }
}
