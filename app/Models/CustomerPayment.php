<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment a customer started from their ledger page. "pending" until the
 * gateway's signed result arrives; "paid" ones have been applied to what the
 * customer owes (applied_to lists where each rupee went).
 */
class CustomerPayment extends Model
{
    use BelongsToShop;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = [
        'shop_id', 'customer_id', 'payment_gateway_connection_id', 'gateway', 'reference', 'amount', 'status',
        'response_code', 'response_message', 'gateway_reference', 'applied_to', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'amount' => 'decimal:2',
            'applied_to' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayConnection::class, 'payment_gateway_connection_id');
    }
}
