<?php

namespace App\Models;

use App\Enums\SimForm;
use App\Enums\SimType;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimSale extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'customer_id',
        'network',
        'sim_type',
        'sim_form',
        'is_duplicate',
        'sim_number',
        'amount',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'sim_type' => SimType::class,
            'sim_form' => SimForm::class,
            'is_duplicate' => 'boolean',
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
        return 'SS-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
