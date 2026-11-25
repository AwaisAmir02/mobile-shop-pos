<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money a vendor paid back against their credit: it comes into the shop's cash or account. */
class VendorCreditRefund extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'vendor_credit_id', 'amount', 'payment_method', 'shop_account_id', 'refund_date', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refund_date' => 'date'];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(VendorCredit::class, 'vendor_credit_id');
    }
}
