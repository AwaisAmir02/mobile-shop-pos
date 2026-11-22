<?php

namespace App\Models;

use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** The traded-in phone, for a Trade-in payment. */
    public function buyback(): HasOne
    {
        return $this->hasOne(Buyback::class, 'sale_payment_id')->withoutGlobalScopes();
    }

    public function methodLabel(): string
    {
        if ($this->payment_method === SalePaymentMethod::TradeIn) {
            $buyback = $this->buyback;

            return $buyback ? 'Trade-in: '.$buyback->phoneName().($buyback->imei ? ' · IMEI '.$buyback->imei : '') : 'Trade-in';
        }

        return match ($this->payment_method) {
            SalePaymentMethod::Cash => 'Cash',
            SalePaymentMethod::StoreCredit => 'Store credit',
            SalePaymentMethod::Exchange => ($exchange = SaleReturn::withoutGlobalScopes()->where('exchange_sale_id', $this->sale_id)->latest('id')->first())
                ? 'Exchange: '.$exchange->number().' on INV-'.str_pad((string) $exchange->sale_id, 6, '0', STR_PAD_LEFT)
                : 'Exchange',
            SalePaymentMethod::Account => $this->shopAccount
                ? $this->shopAccount->name.' · '.$this->shopAccount->provider_type
                : 'Shop account (removed)',
            default => 'Not recorded',
        };
    }
}
