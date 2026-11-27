<?php

namespace App\Actions;

use App\Enums\OnlineTool;
use App\Models\CustomerPayment;
use App\Models\PaymentGatewayConnection;
use App\Models\Sale;
use App\Models\SalePaymentLink;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A JazzCash link for one order (O7): an online buyer pays for just this sale,
 * in full or an advance the shop sets (say, the delivery charge, so a refusal
 * costs nothing). Paid through the shop's own JazzCash merchant account, the
 * same hosted checkout the /k/ account link uses (App\Payments\JazzCash).
 *
 * Only what JazzCash's signed result says is paid is recorded, and only on
 * this sale (CustomerPayments::completeJazzCash). A sale whose cash a courier
 * is collecting can't be paid here: the buyer would pay twice.
 */
class SalePaymentLinks
{
    public static function available(Shop $shop): bool
    {
        return $shop->usesTool(OnlineTool::PaymentLinks) && PaymentGatewayConnection::activeFor($shop->id) !== null;
    }

    /** A new link for the sale; any earlier one stops working. */
    public static function create(Sale $sale, ?float $amount, ?int $userId): SalePaymentLink
    {
        self::assertPayable($sale);

        if ($amount !== null && ($amount < CustomerPayments::MIN_AMOUNT || $amount > round($sale->amountDue(), 2))) {
            throw ValidationException::withMessages(['linkAmount' => 'Ask for between Rs '.CustomerPayments::MIN_AMOUNT.' and Rs '.number_format($sale->amountDue(), 2).', or leave it blank for the whole amount.']);
        }

        return DB::transaction(function () use ($sale, $amount, $userId) {
            SalePaymentLink::where('sale_id', $sale->id)->whereNull('cancelled_at')->update(['cancelled_at' => now()]);

            return SalePaymentLink::create([
                'shop_id' => $sale->shop_id,
                'sale_id' => $sale->id,
                'token' => Str::random(40),
                'amount' => $amount !== null ? round($amount, 2) : null,
                'created_by' => $userId,
            ]);
        });
    }

    public static function cancel(SalePaymentLink $link): void
    {
        $link->update(['cancelled_at' => now()]);
    }

    /** What the link asks for now: its set amount, never more than is still owed. */
    public static function amountDue(SalePaymentLink $link): float
    {
        $owed = round($link->sale->amountDue(), 2);

        return $link->amount !== null ? min((float) $link->amount, $owed) : $owed;
    }

    /** Opens a JazzCash payment for the link's amount. */
    public static function start(SalePaymentLink $link, PaymentGatewayConnection $connection): CustomerPayment
    {
        if ($link->cancelled_at !== null) {
            throw ValidationException::withMessages(['amount' => 'This payment link has been replaced. Ask the shop for the new one.']);
        }

        self::assertPayable($link->sale);
        $amount = self::amountDue($link);

        if ($amount < CustomerPayments::MIN_AMOUNT) {
            throw ValidationException::withMessages(['amount' => 'This order is already paid.']);
        }

        return CustomerPayment::create([
            'shop_id' => $link->shop_id,
            'customer_id' => $link->sale->customer_id,
            'sale_payment_link_id' => $link->id,
            'payment_gateway_connection_id' => $connection->id,
            'gateway' => $connection->gateway,
            // JazzCash allows 20 characters.
            'reference' => 'HO'.now('Asia/Karachi')->format('ymdHis').strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
            'amount' => $amount,
            'status' => CustomerPayment::PENDING,
        ]);
    }

    protected static function assertPayable(?Sale $sale): void
    {
        if ($sale === null || $sale->isCancelled()) {
            throw ValidationException::withMessages(['amount' => 'This order was cancelled.']);
        }

        if ($sale->customer_id === null) {
            throw ValidationException::withMessages(['amount' => 'Choose the customer on this sale first: a payment link pays a customer\'s order.']);
        }

        if ($sale->amountDue() < CustomerPayments::MIN_AMOUNT) {
            throw ValidationException::withMessages(['amount' => 'This order is already paid.']);
        }

        if ($sale->heldByCourier() !== null) {
            throw ValidationException::withMessages(['amount' => 'The courier is collecting this order\'s cash on delivery, so it can\'t be paid online too.']);
        }
    }
}
