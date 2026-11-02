<?php

namespace App\Models;

use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'user_id',
        'amount',
        'payment_date',
        'payment_method',
        'shop_account_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'payment_method' => SalePaymentMethod::class,
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    public function methodLabel(): string
    {
        return match ($this->payment_method) {
            SalePaymentMethod::Cash => 'Cash',
            SalePaymentMethod::Account => $this->shopAccount
                ? $this->shopAccount->name.' · '.$this->shopAccount->provider_type
                : 'Shop account (removed)',
            default => 'Not recorded',
        };
    }
}
