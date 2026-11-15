<?php

namespace App\Actions;

use App\Enums\PaymentGateway;
use App\Enums\SalePaymentMethod;
use App\Enums\UdhaarTransactionType;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\NadraVerification;
use App\Models\PaymentGatewayConnection;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\ServicePayment;
use App\Models\SimSale;
use App\Models\UdhaarTransaction;
use App\Models\WalletLoad;
use App\Payments\JazzCash;
use App\Support\ApiShopContext;
use App\Support\CustomerDuesStatement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer paying what they owe from their own ledger page, through the
 * shop's own JazzCash merchant account.
 *
 * Nothing is recorded until JazzCash's signed result says it's paid. Then the
 * money goes, oldest first, to the udhaar loan balance and then each unpaid
 * item (sales, repairs, bills…) through the same actions a shopkeeper's
 * "Record Payment" uses; anything left over is kept as udhaar advance.
 */
class CustomerPayments
{
    public const MIN_AMOUNT = 10;

    public static function start(Customer $customer, PaymentGatewayConnection $connection, float $amount): CustomerPayment
    {
        $owed = round(CustomerDuesStatement::for($customer)->total(), 2);
        $amount = round($amount, 2);

        if ($owed < self::MIN_AMOUNT) {
            throw ValidationException::withMessages(['amount' => 'Nothing is owed right now.']);
        }

        if ($amount < self::MIN_AMOUNT || $amount > $owed) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount between Rs '.self::MIN_AMOUNT.' and Rs '.number_format($owed, 2).'.']);
        }

        return CustomerPayment::create([
            'shop_id' => $customer->shop_id,
            'customer_id' => $customer->id,
            'payment_gateway_connection_id' => $connection->id,
            'gateway' => $connection->gateway,
            // JazzCash allows 20 characters.
            'reference' => 'HK'.now('Asia/Karachi')->format('ymdHis').strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
            'amount' => $amount,
            'status' => CustomerPayment::PENDING,
        ]);
    }

    /**
     * JazzCash's result (from the customer's browser or JazzCash's IPN). Safe
     * to receive more than once. Returns the payment, or null when the result
     * doesn't belong to any payment we started or its signature is wrong.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function completeJazzCash(array $fields): ?CustomerPayment
    {
        $reference = (string) ($fields['pp_TxnRefNo'] ?? '');
        $payment = CustomerPayment::withoutGlobalScopes()->where('reference', $reference)->where('gateway', PaymentGateway::JazzCash->value)->first();

        if ($payment === null) {
            return null;
        }

        $connection = PaymentGatewayConnection::withoutGlobalScopes()->find($payment->payment_gateway_connection_id);

        if ($connection === null || ! JazzCash::verify($fields, (string) $connection->credential('integrity_salt'))) {
            return null;
        }

        return ApiShopContext::current()->run($payment->shop_id, fn () => DB::transaction(function () use ($payment, $connection, $fields) {
            $payment = CustomerPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === CustomerPayment::PAID) {
                return $payment;
            }

            $code = (string) ($fields['pp_ResponseCode'] ?? '');
            $amountMatches = (int) ($fields['pp_Amount'] ?? -1) === JazzCash::paisa((float) $payment->amount);
            $message = mb_substr((string) ($fields['pp_ResponseMessage'] ?? ''), 0, 255);

            $payment->fill(['response_code' => mb_substr($code, 0, 20), 'response_message' => $message ?: null, 'gateway_reference' => mb_substr((string) ($fields['pp_RetreivalReferenceNo'] ?? $fields['pp_RetrievalReferenceNo'] ?? ''), 0, 100) ?: null]);

            if (in_array($code, JazzCash::PAID_CODES, true) && $amountMatches) {
                $payment->fill(['status' => CustomerPayment::PAID, 'paid_at' => now()]);
                $payment->applied_to = self::apply($payment, $connection);
                $connection->update(['last_paid_at' => now()]);
            } elseif (! in_array($code, JazzCash::PENDING_CODES, true)) {
                $payment->status = CustomerPayment::FAILED;
            }

            $payment->save();

            return $payment;
        }));
    }

    /**
     * Spreads a paid amount over what the customer owes, oldest first.
     *
     * @return list<array{source: string, id: ?int, amount: float}>
     */
    protected static function apply(CustomerPayment $payment, PaymentGatewayConnection $connection): array
    {
        $customer = Customer::withoutGlobalScopes()->findOrFail($payment->customer_id);
        [$method, $accountId] = $connection->shop_account_id ? [SalePaymentMethod::Account->value, $connection->shop_account_id] : [null, null];
        $note = 'Paid online by '.$connection->gateway->label().' ('.$payment->reference.')';
        $left = round((float) $payment->amount, 2);
        $applied = [];

        $repay = function (float $amount) use ($customer, $method, $accountId, $note, &$applied) {
            UdhaarTransaction::create([
                'shop_id' => $customer->shop_id,
                'customer_id' => $customer->id,
                'type' => UdhaarTransactionType::Repayment->value,
                'amount' => $amount,
                'transaction_date' => now()->toDateString(),
                'note' => $note,
                'payment_method' => $method,
                'shop_account_id' => $accountId,
            ]);
            $applied[] = ['source' => 'udhaar', 'id' => null, 'amount' => $amount];
        };

        $statement = CustomerDuesStatement::for($customer);

        if (($udhaar = round($statement->udhaarBalance(), 2)) > 0 && $left > 0) {
            $take = min($left, $udhaar);
            $repay($take);
            $left = round($left - $take, 2);
        }

        foreach ($statement->items()->sortBy('date') as $item) {
            if ($left <= 0) {
                break;
            }

            $record = self::recordFor($item['source'], $item['id']);

            if ($record === null) {
                continue;
            }

            $take = round(min($left, (float) $item['amountDue']), 2);

            if ($record instanceof Sale) {
                RecordSalePayment::handle($record, $take, null, $method, $accountId);
            } else {
                RecordModulePayment::handle($record, $take, $method, $accountId, null);
            }

            $applied[] = ['source' => $item['source'], 'id' => $item['id'], 'amount' => $take];
            $left = round($left - $take, 2);
        }

        // Paid more than the dues just checked (a sale settled meanwhile): kept as advance.
        if ($left > 0) {
            $repay($left);
        }

        return $applied;
    }

    protected static function recordFor(string $source, int $id): ?object
    {
        $class = match ($source) {
            'sale' => Sale::class,
            'wallet_load' => WalletLoad::class,
            'balance_load' => BalanceLoad::class,
            'bill_payment' => BillPayment::class,
            'repair' => Repair::class,
            'nadra_verification' => NadraVerification::class,
            'sim_sale' => SimSale::class,
            'service_payment' => ServicePayment::class,
            default => null,
        };

        return $class ? $class::find($id) : null;
    }
}
