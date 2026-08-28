<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPayment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'customer_id',
        'bill_category_id',
        'bill_provider_id',
        'consumer_number',
        'consumer_name',
        'amount',
        'fee',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billCategory(): BelongsTo
    {
        return $this->belongsTo(BillCategory::class);
    }

    public function billProvider(): BelongsTo
    {
        return $this->belongsTo(BillProvider::class);
    }

    public function receiptNumber(): string
    {
        return 'BP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
