<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A JazzCash link that pays one order (O7, App\Actions\SalePaymentLinks), apart
 * from the customer's /k/ account link that pays everything they owe. Whoever
 * has the link can pay; it never shows anything but this order.
 */
class SalePaymentLink extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'sale_id', 'token', 'amount', 'created_by', 'cancelled_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScope(ExcludesCancelledSalesScope::class);
    }

    public function url(): string
    {
        return route('order-payment.show', $this->token);
    }
}
