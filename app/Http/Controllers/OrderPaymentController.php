<?php

namespace App\Http\Controllers;

use App\Actions\SalePaymentLinks;
use App\Models\PaymentGatewayConnection;
use App\Models\SalePaymentLink;
use App\Models\Shop;
use App\Payments\JazzCash;
use App\Support\ApiShopContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * One order's payment page (O7), opened from the link the shop sends: what the
 * order is and what's asked, and a button to JazzCash. Nothing else about the
 * customer is shown. JazzCash's result comes back to the shared
 * payments.jazzcash.return (CustomerLedgerController), which sends the buyer here.
 */
class OrderPaymentController extends Controller
{
    protected function link(string $token): SalePaymentLink
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);

        return SalePaymentLink::withoutGlobalScopes()->where('token', $token)->firstOrFail();
    }

    public function show(string $token): View
    {
        $link = $this->link($token);

        return ApiShopContext::current()->run($link->shop_id, function () use ($link) {
            $sale = $link->sale()->with('items')->first();

            return view('order-payment.show', [
                'link' => $link,
                'sale' => $sale,
                'shop' => Shop::findOrFail($link->shop_id),
                'amount' => $sale !== null && ! $sale->isCancelled() ? SalePaymentLinks::amountDue($link) : 0.0,
                'gateway' => PaymentGatewayConnection::activeFor($link->shop_id),
            ]);
        });
    }

    public function pay(string $token): View|RedirectResponse
    {
        $link = $this->link($token);
        $connection = PaymentGatewayConnection::activeFor($link->shop_id) ?? abort(404);

        return ApiShopContext::current()->run($link->shop_id, function () use ($link, $connection, $token) {
            try {
                $payment = SalePaymentLinks::start($link, $connection);
            } catch (ValidationException $e) {
                return redirect()->route('order-payment.show', $token)->withErrors($e->errors());
            }

            $shop = Shop::findOrFail($link->shop_id);

            return view('customer-ledger.redirect', [
                'action' => JazzCash::actionUrl($connection),
                'fields' => JazzCash::checkoutFields($connection, $payment, route('payments.jazzcash.return'), $shop->invoiceDisplayName().' order '.$link->sale->invoiceNumber()),
            ]);
        });
    }
}
