<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event on a customer's store credit. See App\Actions\StoreCredit.
 * A customer's credit is good at every branch, so rows are never hidden by
 * branch; branch_id only records where it happened.
 */
class StoreCreditEntry extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    public const ISSUE = 'issue';

    public const SPEND = 'spend';

    public const CASH_OUT = 'cash_out';

    public const EXPIRE = 'expire';

    protected $fillable = [
        'shop_id',
        'branch_id',
        'customer_id',
        'user_id',
        'type',
        'amount',
        'remaining',
        'expires_on',
        'entry_date',
        'sale_return_id',
        'sale_id',
        'sale_payment_id',
        'payment_method',
        'shop_account_id',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'active', 'remaining' => 0];

    public static function appliesBranchScope(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining' => 'decimal:2',
            'expires_on' => 'date',
            'entry_date' => 'date',
            'payment_method' => SalePaymentMethod::class,
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class)->withoutGlobalScopes();
    }

    public function draws(): HasMany
    {
        return $this->hasMany(StoreCreditDraw::class, 'entry_id');
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class);
    }

    public function number(): string
    {
        return 'SC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** "Issued on return RN-000004", "Spent on INV-000012", "Cashed out from Cash", "Expired". */
    public function describe(): string
    {
        return match ($this->type) {
            self::ISSUE => $this->sale_return_id ? 'Issued on return RN-'.str_pad((string) $this->sale_return_id, 6, '0', STR_PAD_LEFT) : 'Issued',
            self::SPEND => $this->sale_id ? 'Spent on INV-'.str_pad((string) $this->sale_id, 6, '0', STR_PAD_LEFT) : 'Spent',
            self::CASH_OUT => 'Cashed out from '.($this->shop_account_id ? (ShopAccount::withoutGlobalScopes()->find($this->shop_account_id)?->name ?? 'a shop account') : ($this->payment_method?->label() ?? 'not recorded')),
            self::EXPIRE => 'Expired',
            default => ucfirst((string) $this->type),
        };
    }
}
