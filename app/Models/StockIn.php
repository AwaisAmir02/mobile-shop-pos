<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIn extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'product_id',
        'product_name',
        'user_id',
        'quantity',
        'total_cost',
        'payment_status',
        'amount_paid',
        'stock_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'total_cost' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'amount_paid' => 'decimal:2',
            'stock_date' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function amountOwed(): float
    {
        if ($this->payment_status === PaymentStatus::Paid) {
            return 0.0;
        }

        return max(0.0, (float) ($this->total_cost ?? 0) - (float) $this->amount_paid);
    }
}
