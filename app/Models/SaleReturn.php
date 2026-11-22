<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A return against an earlier sale: the credit note (RN-000123). What the
 * goods were worth (value_total) first cancels what the customer still owed
 * on that sale (due_reduction); the rest was refunded. The sale's own total
 * and payments are never touched. See App\Actions\SaleReturns.
 */
class SaleReturn extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    protected $fillable = [
        'shop_id',
        'branch_id',
        'sale_id',
        'customer_id',
        'user_id',
        'return_date',
        'value_total',
        'due_reduction',
        'refund_cash',
        'refund_method',
        'shop_account_id',
        'refund_credit',
        'refund_exchange',
        'exchange_sale_id',
        'reason',
        'late_reason',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'value_total' => 'decimal:2',
            'due_reduction' => 'decimal:2',
            'refund_cash' => 'decimal:2',
            'refund_method' => SalePaymentMethod::class,
            'refund_credit' => 'decimal:2',
            'refund_exchange' => 'decimal:2',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function number(): string
    {
        return 'RN-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScope(ExcludesCancelledSalesScope::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    /** "Cash", or the account's name. */
    public function refundSourceLabel(): string
    {
        return $this->shop_account_id !== null
            ? (ShopAccount::withoutGlobalScopes()->find($this->shop_account_id)?->name ?? 'Shop account')
            : ($this->refund_method?->label() ?? 'Not recorded');
    }
}
