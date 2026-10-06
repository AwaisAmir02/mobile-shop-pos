<?php

namespace App\Models;

use App\Enums\PlanType;
use App\Enums\SubscriptionInvoiceStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    protected $fillable = [
        'shop_id',
        'invoice_number',
        'plan_type',
        'period_start',
        'months',
        'period_end',
        'amount',
        'discount',
        'total',
        'status',
        'paid_at',
        'notes',
        'cancelled_at',
        'cancel_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'date',
            'cancelled_at' => 'datetime',
            'months' => 'integer',
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => SubscriptionInvoiceStatus::class,
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function totalFor(float $amount, float $discount): float
    {
        return round(max(0.0, $amount - $discount), 2);
    }

    public static function periodEndFor(Carbon $start, int $months): Carbon
    {
        return $start->copy()->addMonthsNoOverflow($months)->subDay();
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionInvoiceStatus::Cancelled;
    }

    public function isPaid(): bool
    {
        return $this->status === SubscriptionInvoiceStatus::Paid;
    }

    public function planLabel(): string
    {
        return PlanType::from($this->plan_type)->label();
    }
}
