<?php

namespace App\Enums;

use App\Models\Scopes\ShopScope;
use App\Models\ShopAccount;
use Illuminate\Validation\ValidationException;

enum SalePaymentMethod: string
{
    case Cash = 'cash';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Account => 'Shop account',
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
