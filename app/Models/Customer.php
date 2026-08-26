<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'address',
    ];

    /**
     * Whether this customer has any financial history (sales, udhaar, etc.)
     * that should block deletion. Extended by later modules as they add
     * their own customer-linked records.
     */
    public function hasFinancialHistory(): bool
    {
        return false;
    }
}
