<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIn extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'product_id',
        'vendor_id',
        'product_name',
        'user_id',
        'quantity',
        'cost_price',
        'selling_price',
        'quantity_remaining',
        'total_cost',
        'payment_status',
        'amount_paid',
        'stock_date',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'quantity_remaining' => 'integer',
            'total_cost' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'amount_paid' => 'decimal:2',
            'stock_date' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function amountOwed(): float
    {
        if ($this->payment_status === PaymentStatus::Paid) {
            return 0.0;
        }

        return max(0.0, (float) ($this->total_cost ?? 0) - (float) $this->amount_paid);
    }

    public static function availableBatchesFor(int $productId)
    {
        return static::query()
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('stock_date')
            ->orderBy('id');
    }
}
