<?php

namespace App\Models;

use App\Enums\RepairCategory;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Repair extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'customer_id',
        'category',
        'description',
        'amount',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'category' => RepairCategory::class,
            'amount' => 'decimal:2',
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

    public function receiptNumber(): string
    {
        return 'RP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
