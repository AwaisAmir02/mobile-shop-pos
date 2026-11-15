<?php

namespace App\Payments;

use App\Models\CustomerPayment;
use App\Models\PaymentGatewayConnection;

/**
 * JazzCash hosted checkout (Page Redirection, version 1.1), with the shop's
 * own merchant account: Merchant ID, Password and Integrity Salt from the
 * JazzCash merchant portal.
 *
 * The customer's browser posts a signed form to JazzCash; JazzCash posts the
 * result, signed the same way, to our return URL (and to the IPN URL if the
 * shop sets one in its JazzCash portal). Only a result whose signature checks
 * out against the shop's salt, for the amount we asked, is taken as paid.
 *
 * Signature: every non-empty field whose name starts with "pp" (except
 * pp_SecureHash), sorted by name, values joined with "&" after the salt,
 * HMAC-SHA256 keyed with the salt, hex.
 */
class JazzCash
{
    public const LIVE = 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/';

    public const SANDBOX = 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/';

    /** 000 paid; 121 paid and confirmed (e.g. a voucher paid at a shop). */
    public const PAID_CODES = ['000', '121'];

    /** Still waiting on the customer (e.g. a voucher not paid yet). */
    public const PENDING_CODES = ['124', '157'];

    public static function actionUrl(PaymentGatewayConnection $connection): string
    {
        return $connection->credential('environment') === 'sandbox' ? self::SANDBOX : self::LIVE;
    }

    /** @param array<string, mixed> $fields */
    public static function sign(array $fields, string $salt): string
    {
        $values = collect($fields)
            ->filter(fn ($value, $key) => str_starts_with((string) $key, 'pp') && $key !== 'pp_SecureHash' && (string) $value !== '')
            ->sortKeys(SORT_STRING)
            ->values()
            ->all();

        // Lowercase hex, as JazzCash's own samples send it; checked case-insensitively below.
        return hash_hmac('sha256', $salt.'&'.implode('&', $values), $salt);
    }

    /** @param array<string, mixed> $fields */
    public static function verify(array $fields, string $salt): bool
    {
        $given = (string) ($fields['pp_SecureHash'] ?? '');

        return $given !== '' && hash_equals(self::sign($fields, $salt), strtolower($given));
    }

    /**
     * The hidden fields for the checkout form.
     *
     * @return array<string, string>
     */
    public static function checkoutFields(PaymentGatewayConnection $connection, CustomerPayment $payment, string $returnUrl, string $description): array
    {
        $now = now('Asia/Karachi');

        $fields = [
            'pp_Version' => '1.1',
            'pp_TxnType' => '',
            'pp_Language' => 'EN',
            'pp_MerchantID' => (string) $connection->credential('merchant_id'),
            'pp_SubMerchantID' => '',
            'pp_Password' => (string) $connection->credential('password'),
            'pp_BankID' => '',
            'pp_ProductID' => '',
            'pp_TxnRefNo' => $payment->reference,
            'pp_Amount' => (string) self::paisa((float) $payment->amount),
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => $now->format('YmdHis'),
            'pp_BillReference' => 'C'.$payment->customer_id,
            'pp_Description' => mb_substr(preg_replace('/[^A-Za-z0-9 .,\-]/', '', $description) ?: 'Payment', 0, 100),
            'pp_TxnExpiryDateTime' => $now->copy()->addDay()->format('YmdHis'),
            'pp_ReturnURL' => $returnUrl,
            'ppmpf_1' => '',
            'ppmpf_2' => '',
            'ppmpf_3' => '',
            'ppmpf_4' => '',
            'ppmpf_5' => '',
        ];

        $fields['pp_SecureHash'] = self::sign($fields, (string) $connection->credential('integrity_salt'));

        return $fields;
    }

    public static function paisa(float $rupees): int
    {
        return (int) round($rupees * 100);
    }
}
