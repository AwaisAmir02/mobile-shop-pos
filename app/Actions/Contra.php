<?php

namespace App\Actions;

use App\Enums\SalePaymentMethod;
use App\Enums\StockInPaymentSource;
use App\Models\ContraSettlement;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A party the shop both buys from and sells to (W11, mostly traders): the
 * customer record is linked to its vendor record (customers.vendor_id), and
 * what each side owes can be set off against the other in one record.
 *
 * No money moves, so neither Cash & Balances nor any bank sees it. The
 * customer side pays their oldest unpaid sale bills (sale payments of method
 * contra); the vendor side pays the oldest unpaid Stock In (stock-in payments
 * of method contra). Both point at one clearing account in the ledger, which
 * nets to nothing. Udhaar loans are never touched: they stay a separate kind
 * of money, so the rule in Customer::salesOutstandingBalance holds.
 */
class Contra
{
    public const METHOD = 'contra';

    /** @return array{customer_owes: float, vendor_owed: float, most: float} */
    public static function position(Customer $customer): array
    {
        $vendor = $customer->vendor_id ? Vendor::find($customer->vendor_id) : null;
        $customerOwes = round($customer->salesOutstandingBalance(), 2);
        $vendorOwed = $vendor ? VendorCredits::owedTo($vendor->id) : 0.0;

        return ['customer_owes' => $customerOwes, 'vendor_owed' => $vendorOwed, 'most' => round(min($customerOwes, $vendorOwed), 2)];
    }

    public static function settle(Customer $customer, float $amount, ?string $note, ?int $userId): ContraSettlement
    {
        if ($customer->vendor_id === null) {
            throw ValidationException::withMessages(['contraAmount' => 'Link this customer to their vendor record first.']);
        }

        return DB::transaction(function () use ($customer, $amount, $note, $userId) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $amount = round($amount, 2);
            $position = self::position($customer);

            if ($amount <= 0 || $amount > $position['most']) {
                throw ValidationException::withMessages(['contraAmount' => 'Enter up to Rs '.number_format($position['most'], 2).': the smaller of what they owe on bills and what you owe them.']);
            }

            $settlement = ContraSettlement::create([
                'customer_id' => $customer->id,
                'vendor_id' => $customer->vendor_id,
                'amount' => $amount,
                'settled_on' => now()->toDateString(),
                'applied_to' => [],
                'note' => filled($note) ? mb_substr($note, 0, 255) : null,
                'status' => 'active',
                'user_id' => $userId,
            ]);
            $label = 'Contra '.$settlement->number();

            $customerSide = CustomerPayments::allocate($customer, $amount, function (string $source, ?object $record, float $part) use ($label, $userId) {
                if ($record instanceof Sale) {
                    self::salePayment($record, $part, $label, $userId);
                }

                return null;
            }, ['sale']);

            $vendorSide = VendorCredits::payBatches($customer->vendor_id, $amount, StockInPaymentSource::Contra, self::METHOD, null, $userId, $label);

            $settlement->update(['applied_to' => [
                'customer' => array_values(array_map(fn ($p) => ['sale_id' => $p['id'], 'reference' => $p['reference'], 'amount' => $p['amount']], $customerSide)),
                'vendor' => $vendorSide,
            ]]);

            return $settlement;
        });
    }

    /** Undoes a set-off: both sides owe again what they did before it. */
    public static function cancel(ContraSettlement $settlement, ?int $userId): void
    {
        DB::transaction(function () use ($settlement, $userId) {
            $settlement = ContraSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($settlement->isCancelled()) {
                return;
            }

            $label = 'Contra '.$settlement->number().' undone';

            foreach ($settlement->applied_to['customer'] ?? [] as $part) {
                if (($sale = Sale::withoutGlobalScopes()->find($part['sale_id'])) !== null) {
                    self::salePayment($sale, -(float) $part['amount'], $label, $userId);
                }
            }

            VendorCredits::unpayBatches($settlement->applied_to['vendor'] ?? [], StockInPaymentSource::Contra, self::METHOD, null, $userId, $label);
            $settlement->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        });
    }

    protected static function salePayment(Sale $sale, float $amount, string $note, ?int $userId): void
    {
        SalePayment::create([
            'shop_id' => $sale->shop_id,
            'sale_id' => $sale->id,
            'user_id' => $userId,
            'amount' => round($amount, 2),
            'payment_date' => now()->toDateString(),
            'payment_method' => SalePaymentMethod::Contra->value,
            'shop_account_id' => null,
            'note' => $note,
        ]);
    }
}
