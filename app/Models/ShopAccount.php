<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * branch_id is set only for a cash drawer kept at one branch. Bank and
 * wallet accounts leave it null and are shared by every branch.
 */
class ShopAccount extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'branch_id',
        'name',
        'provider_type',
        'account_title',
        'account_number',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Shared accounts plus, when a branch is given, that branch's own cash drawers. */
    public function scopeUsableAt(Builder $query, ?int $branchId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('branch_id')->when($branchId, fn (Builder $inner) => $inner->orWhere('branch_id', $branchId)));
    }

    public function walletLoads(): HasMany
    {
        return $this->hasMany(WalletLoad::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function hasPaymentDetails(): bool
    {
        return filled($this->account_number);
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
