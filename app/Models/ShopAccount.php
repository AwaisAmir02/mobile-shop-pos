<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopAccount extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'provider_type',
    ];

    public function walletLoads(): HasMany
    {
        return $this->hasMany(WalletLoad::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }
}
