<?php

namespace App\Http\Controllers;

use App\Actions\CustomerPayments;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentGatewayConnection;
use App\Models\Shop;
use App\Payments\JazzCash;
use App\Support\ApiShopContext;
use App\Support\CustomerLedgerView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A customer's own read-only ledger, at a private link with no login (like the
 * WhatsApp reminder that carries it), and the Pay button on it. Everything is
 * read and written as that customer's shop (ApiShopContext), never another's.
 */
class CustomerLedgerController extends Controller
{
    protected function customer(string $token): Customer
    {
        abort_unless(strlen($token) === 32 && ctype_alnum($token), 404);

        return Customer::withoutGlobalScopes()->where('ledger_token', $token)->firstOrFail();
    }

    public function show(string $token): View
    {
        $customer = $this->customer($token);

        return ApiShopContext::current()->run($customer->shop_id, function () use ($customer) {
            $view = new CustomerLedgerView($customer);
            $shop = Shop::findOrFail($customer->shop_id);

            return view('customer-ledger.show', [
                'customer' => $customer,
                'shop' => $shop,
                'total' => $view->total(),
                'owed' => $view->owed(),
                'history' => $view->history(),
                'gateway' => PaymentGatewayConnection::activeFor($customer->shop_id),
                'token' => $customer->ledger_token,
            ]);
        });
    }

    public function pay(Request $request, string $token): View|RedirectResponse
    {
        $customer = $this->customer($token);
        $connection = PaymentGatewayConnection::activeFor($customer->shop_id) ?? abort(404);
        $data = $request->validate(['amount' => ['required', 'numeric']]);

        return ApiShopContext::current()->run($customer->shop_id, function () use ($customer, $connection, $data, $token) {
            try {
                $payment = CustomerPayments::start($customer, $connection, (float) $data['amount']);
            } catch (ValidationException $e) {
                return redirect()->route('customer-ledger.show', $token)->withErrors($e->errors());
            }

            $shop = Shop::findOrFail($customer->shop_id);

            return view('customer-ledger.redirect', [
                'action' => JazzCash::actionUrl($connection),
                'fields' => JazzCash::checkoutFields($connection, $payment, route('payments.jazzcash.return'), $shop->invoiceDisplayName().' account'),
            ]);
        });
    }

    /** JazzCash posts the signed result here, from the customer's browser and as its IPN. */
    public function jazzcashReturn(Request $request): RedirectResponse|\Illuminate\Http\Response
    {
        $payment = CustomerPayments::completeJazzCash($request->all());

        if ($payment === null) {
            return response('Unknown or unsigned payment result.', 400);
        }

        $customer = Customer::withoutGlobalScopes()->findOrFail($payment->customer_id);
        $flash = match ($payment->status) {
            CustomerPayment::PAID => ['paid' => 'Thank you! Rs '.number_format((float) $payment->amount, 2).' has been paid.'],
            CustomerPayment::PENDING => ['pending' => 'Your payment is waiting to be completed: '.($payment->response_message ?: 'please finish it with JazzCash').'.'],
            default => ['failed' => 'The payment didn\'t go through'.($payment->response_message ? ': '.$payment->response_message : '').'. Nothing was taken.'],
        };

        // The IPN is a server call with no customer to show a page to.
        if ($request->expectsJson() || ! $customer->ledger_token) {
            return response('OK');
        }

        return redirect()->route('customer-ledger.show', $customer->ledger_token)->with($flash);
    }
}
