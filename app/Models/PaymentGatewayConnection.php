<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\PaymentGateway;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's own merchant account with a payment gateway, used for the Pay
 * button on its customers' ledger pages. Credentials are encrypted. The shop
 * account is where the money lands, so payments show in Cash & Balances.
 */
class PaymentGatewayConnection extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'gateway', 'credentials', 'shop_account_id', 'status', 'last_paid_at', 'connected_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'credentials' => 'encrypted:array',
            'status' => ConnectionStatus::class,
            'last_paid_at' => 'datetime',
        ];
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Connected;
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** The shop's active gateway for customer payments, if any. */
    public static function activeFor(int $shopId): ?self
    {
        return self::withoutGlobalScopes()->where('shop_id', $shopId)->where('status', ConnectionStatus::Connected->value)
            ->where('gateway', PaymentGateway::JazzCash->value)->first();
    }
}
