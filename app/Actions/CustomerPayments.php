<?php

namespace App\Actions;

use App\Enums\PaymentGateway;
use App\Enums\SalePaymentMethod;
use App\Enums\UdhaarTransactionType;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerReceipt;
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
 *
 * The same spreading serves a lump sum taken at the counter (receiveAtCounter,
 * W1): one receipt for the customer, the money recorded on what it paid.
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

        // Stored as it always was: an advance is an udhaar repayment, and only source/id/amount.
        return array_map(
            fn (array $part) => ['source' => $part['source'] === 'advance' ? 'udhaar' : $part['source'], 'id' => $part['id'], 'amount' => $part['amount']],
            self::allocate($customer, (float) $payment->amount, self::payer($customer, $method, $accountId, $note, null)),
        );
    }

    /**
     * A lump sum taken from the customer at the counter (W1): spread over
     * what they owe exactly as an online payment is, under one receipt.
     * More than they owe is allowed; the rest is kept as udhaar advance.
     */
    /** @param  list<string>|null  $only  the kinds of dues it may pay (e.g. ['udhaar', 'sale'] for a cheque); null = all */
    public static function receiveAtCounter(Customer $customer, float $amount, ?string $method, ?int $shopAccountId, ?int $userId, ?string $note = null, ?array $only = null): CustomerReceipt
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['receiptAmount' => 'Enter the amount received.']);
        }

        return DB::transaction(function () use ($customer, $amount, $method, $shopAccountId, $userId, $note, $only) {
            // One receipt at a time per customer, so two can't both pay the same bill.
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 200) : null;

            $receipt = CustomerReceipt::create([
                'shop_id' => $customer->shop_id,
                'customer_id' => $customer->id,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_method' => $method,
                'shop_account_id' => $shopAccountId,
                'note' => $note,
                'applied_to' => [],
                'receipt_date' => now()->toDateString(),
            ]);

            $text = 'Receipt '.$receipt->number().($note !== null ? ': '.$note : '');
            $receipt->update(['applied_to' => self::allocate($customer, $amount, self::payer($customer, $method, $shopAccountId, mb_substr($text, 0, 255), $userId), $only)]);

            return $receipt;
        });
    }

    /**
     * Undoes a receipt whose money didn't arrive (a bounced cheque): each bill
     * it paid gets a negative payment, and each udhaar repayment it made a
     * repayment reversal, dated today on the same account. So the dues come
     * back and Cash & Balances shows the money leaving that account again.
     * Only receipts limited to udhaar and sales (cheques) can be reversed.
     */
    public static function reverseReceipt(CustomerReceipt $receipt, string $note, ?int $userId): void
    {
        DB::transaction(function () use ($receipt, $note, $userId) {
            $receipt = CustomerReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->reversed_at !== null) {
                return;
            }

            foreach ($receipt->applied_to as $part) {
                $amount = round((float) $part['amount'], 2);

                if ($part['source'] === 'sale') {
                    \App\Models\SalePayment::create([
                        'shop_id' => $receipt->shop_id,
                        'sale_id' => $part['id'],
                        'user_id' => $userId,
                        'amount' => -$amount,
                        'payment_date' => now()->toDateString(),
                        'payment_method' => $receipt->payment_method,
                        'shop_account_id' => $receipt->shop_account_id,
                        'note' => mb_substr($note, 0, 120),
                    ]);
                } elseif (in_array($part['source'], ['udhaar', 'advance'], true)) {
                    UdhaarTransaction::create([
                        'shop_id' => $receipt->shop_id,
                        'customer_id' => $receipt->customer_id,
                        'user_id' => $userId,
                        'type' => UdhaarTransactionType::RepaymentReversal->value,
                        'amount' => $amount,
                        'transaction_date' => now()->toDateString(),
                        'note' => mb_substr($note, 0, 255),
                        'reverses_transaction_id' => $part['udhaar_transaction_id'] ?? null,
                        'payment_method' => $receipt->payment_method,
                        'shop_account_id' => $receipt->shop_account_id,
                    ]);
                } else {
                    throw new \RuntimeException("A receipt that paid a {$part['source']} can't be reversed here.");
                }
            }

            $receipt->update(['reversed_at' => now()]);
        });
    }

    /**
     * Where an amount goes, oldest first: the udhaar loan balance, then each
     * unpaid item (sales, repairs, bills…) by date; whatever is left is kept
     * as udhaar advance. With no $pay it only reads (a preview); otherwise
     * $pay records each part as it goes.
     *
     * @param  (\Closure(string, ?object, float): void)|null  $pay
     * @return list<array{source: string, id: ?int, reference: string, label: string, amount: float}>
     */
    public static function allocate(Customer $customer, float $amount, ?\Closure $pay = null, ?array $only = null): array
    {
        $left = round($amount, 2);
        $applied = [];
        $statement = CustomerDuesStatement::for($customer);

        if (($only === null || in_array('udhaar', $only, true)) && ($udhaar = round($statement->udhaarBalance(), 2)) > 0 && $left > 0) {
            $take = min($left, $udhaar);
            $made = $pay !== null ? $pay('udhaar', null, $take) : null;

            $applied[] = ['source' => 'udhaar', 'id' => null, 'reference' => 'Udhaar', 'label' => 'Udhaar loan balance', 'amount' => $take]
                + ($made instanceof UdhaarTransaction ? ['udhaar_transaction_id' => $made->id] : []);
            $left = round($left - $take, 2);
        }

        foreach ($statement->items()->sortBy('date') as $item) {
            if ($left <= 0) {
                break;
            }

            if ($only !== null && ! in_array($item['source'], $only, true)) {
                continue;
            }

            $record = self::recordFor($item['source'], $item['id']);

            if ($record === null) {
                continue;
            }

            $take = round(min($left, (float) $item['amountDue']), 2);

            if ($pay !== null) {
                $pay($item['source'], $record, $take);
            }

            $applied[] = ['source' => $item['source'], 'id' => $item['id'], 'reference' => $item['reference'], 'label' => $item['label'], 'amount' => $take];
            $left = round($left - $take, 2);
        }

        // More than the dues just checked (or a sale settled meanwhile): kept as advance.
        if ($left > 0) {
            $made = $pay !== null ? $pay('advance', null, $left) : null;

            $applied[] = ['source' => 'advance', 'id' => null, 'reference' => 'Advance', 'label' => 'Kept as udhaar advance', 'amount' => $left]
                + ($made instanceof UdhaarTransaction ? ['udhaar_transaction_id' => $made->id] : []);
        }

        return $applied;
    }

    /** Records one part of a payment: an udhaar repayment, or a payment on the item it pays. */
    protected static function payer(Customer $customer, ?string $method, ?int $accountId, string $note, ?int $userId): \Closure
    {
        return function (string $source, ?object $record, float $amount) use ($customer, $method, $accountId, $note, $userId) {
            if ($record === null) {
                return UdhaarTransaction::create(array_merge([
                    'shop_id' => $customer->shop_id,
                    'customer_id' => $customer->id,
                    'type' => UdhaarTransactionType::Repayment->value,
                    'amount' => $amount,
                    'transaction_date' => now()->toDateString(),
                    'note' => $note,
                    'payment_method' => $method,
                    'shop_account_id' => $accountId,
                ], $userId !== null ? ['user_id' => $userId] : []));
            }

            if ($record instanceof Sale) {
                RecordSalePayment::handle($record, $amount, $userId, $method, $accountId);
            } else {
                RecordModulePayment::handle($record, $amount, $method, $accountId, $userId);
            }

            return null;
        };
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
