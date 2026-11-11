<?php

namespace App\Models;

use App\Enums\PlanType;
use App\Enums\SubscriptionInvoiceStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionInvoice extends Model
{
    protected $fillable = [
        'shop_id',
        'plan_id',
        'invoice_number',
        'plan_type',
        'period_start',
        'months',
        'period_end',
        'amount',
        'discount',
        'total',
        'items',
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
            'items' => 'array',
            'status' => SubscriptionInvoiceStatus::class,
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** The plan this invoice switches the shop to once paid; null for signup, renewal and add-on invoices. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
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

    /**
     * What the invoice is for, line by line. Invoices from before itemising
     * (and ones an admin types in) are a single line for the whole amount.
     *
     * @return array<int, array{kind: string, label: string, amount: float, shop_addon_id?: int}>
     */
    public function lines(): array
    {
        if ($this->items) {
            return $this->items;
        }

        return [[
            'kind' => 'plan',
            'label' => config('branding.name').' subscription — '.$this->planLabel().' plan, '.$this->months.' '.Str::plural('month', $this->months)
                .' ('.$this->period_start->format('d M Y').' – '.$this->period_end->format('d M Y').')',
            'amount' => (float) $this->amount,
        ]];
    }

    public function planLabel(): string
    {
        return PlanType::from($this->plan_type)->label();
    }
}
