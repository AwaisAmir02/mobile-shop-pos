<?php

namespace App\Models;

use App\Enums\StockInPaymentSource;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated record of money paid to a supplier against a Stock-In. These are
 * history only: StockIn.amount_paid stays the stored total every Stock-In
 * reader uses, and each write path that changes it also writes one row
 * here, so a Stock-In's rows always add up to its amount_paid.
 */
class StockInPayment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'stock_in_id',
        'vendor_invoice_id',
        'payment_method',
        'shop_account_id',
        'user_id',
        'amount',
        'payment_date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'source' => StockInPaymentSource::class,
        ];
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
