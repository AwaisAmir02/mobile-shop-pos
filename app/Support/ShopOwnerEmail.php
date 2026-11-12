<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\User;

/** Where email for a shop goes: the owner's login email, else the shop's own, else nowhere. */
class ShopOwnerEmail
{
    public static function for(Shop|int $shop): ?string
    {
        $shop = $shop instanceof Shop ? $shop : Shop::find($shop);

        if (! $shop) {
            return null;
        }

        $owner = User::where('shop_id', $shop->id)->where('is_owner', true)->value('email');

        foreach ([$owner, $shop->email] as $email) {
            if (filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }
}
