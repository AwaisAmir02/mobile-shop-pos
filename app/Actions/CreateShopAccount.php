<?php

namespace App\Actions;

use App\Models\ShopAccount;
use Illuminate\Validation\Rule;

class CreateShopAccount
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('shop_accounts', 'name')->where('shop_id', $shopId)],
            'providerType' => ['required', 'string', 'max:255'],
        ];
    }

    public static function handle(string $name, string $providerType): ShopAccount
    {
        return ShopAccount::create(['name' => $name, 'provider_type' => $providerType]);
    }
}
