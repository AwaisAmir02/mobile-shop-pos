<?php

namespace App\Actions;

use App\Models\WalletProvider;
use Illuminate\Validation\Rule;

class CreateWalletProvider
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('wallet_providers', 'name')->where('shop_id', $shopId)],
        ];
    }

    public static function handle(string $name): WalletProvider
    {
        return WalletProvider::create(['name' => $name]);
    }
}
