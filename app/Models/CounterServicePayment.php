<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * How money reached a counter service, for shops on the ledger. History only: the
 * record's amount_paid stays the figure every screen uses. Without these rows a
 * later payment by another method would relabel the earlier money.
 */
class CounterServicePayment extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'source_type', 'source_id', 'user_id', 'amount', 'paid_on', 'payment_method', 'shop_account_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }
}
