<?php

namespace App\Enums;

use App\Models\Scopes\ShopScope;
use App\Models\ShopAccount;
use Illuminate\Validation\ValidationException;

enum SalePaymentMethod: string
{
    case Cash = 'cash';
    case Account = 'account';
    /** A used phone the customer handed in, valued as part of the payment (App\Actions\Buybacks). No money moves. */
    case TradeIn = 'trade_in';
    /** The customer's store credit (App\Actions\StoreCredit). No money moves. */
    case StoreCredit = 'store_credit';
    /** Goods handed back in an exchange, paying for the new sale (App\Actions\Exchanges). No money moves. */
    case Exchange = 'exchange';
    /** Set off against what the shop owes the same party as a vendor (App\Actions\Contra). No money moves. */
    case Contra = 'contra';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Account => 'Shop account',
            self::TradeIn => 'Trade-in',
            self::StoreCredit => 'Store credit',
            self::Exchange => 'Exchange',
            self::Contra => 'Contra',
        };
    }

    /**
     * The payment_method / shop_account_id pair to store on a sale payment.
     * Purely descriptive — nothing that totals money reads these columns.
     * The shop check is explicit (not the auth-based ShopScope) so it also
     * holds for writes that pass a shop id, like WhatsApp order acceptance.
     *
     * @return array{payment_method: ?string, shop_account_id: ?int}
     */
    public static function paymentAttributes(?string $method, ?int $shopAccountId, int $shopId, float $amount): array
    {
        $none = ['payment_method' => null, 'shop_account_id' => null];

        if (round($amount, 2) == 0.0 || $method === null || $method === '') {
            return $none;
        }

        $case = self::tryFrom($method)
            ?? throw ValidationException::withMessages(['paymentMethod' => 'Choose a valid payment method.']);

        // Only a sale's own trade-in creates these, with the phone's details.
        if ($case === self::TradeIn) {
            throw ValidationException::withMessages(['paymentMethod' => 'A trade-in is added with the phone\'s details on the New Sale screen.']);
        }

        // Only App\Actions\StoreCredit creates these, drawing on the customer's credit.
        if ($case === self::Exchange) {
            throw ValidationException::withMessages(['paymentMethod' => 'An exchange is made from the sale being exchanged, with Exchange on its receipt.']);
        }

        if ($case === self::Contra) {
            throw ValidationException::withMessages(['paymentMethod' => 'A contra is recorded from the customer\'s page, against their vendor account.']);
        }

        if ($case === self::StoreCredit) {
            throw ValidationException::withMessages(['paymentMethod' => 'Store credit is used through its own option, for a customer who has some.']);
        }

        if ($case === self::Cash) {
            return ['payment_method' => self::Cash->value, 'shop_account_id' => null];
        }

        $belongsToShop = $shopAccountId !== null && ShopAccount::withoutGlobalScope(ShopScope::class)
            ->whereKey($shopAccountId)
            ->where('shop_id', $shopId)
            ->exists();

        if (! $belongsToShop) {
            throw ValidationException::withMessages(['paymentMethod' => 'Choose one of your shop accounts.']);
        }

        return ['payment_method' => self::Account->value, 'shop_account_id' => $shopAccountId];
    }
}
