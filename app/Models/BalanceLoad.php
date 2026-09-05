<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceLoad extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'network',
        'phone_number',
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

    public function receiptNumber(): string
    {
        return 'BL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
