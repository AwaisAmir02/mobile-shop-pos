<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Mirrors BelongsToShop for the branch a transaction was recorded at. Use
 * it after BelongsToShop so shop_id is already filled when this runs.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        if (static::appliesBranchScope()) {
            static::addGlobalScope(new BranchScope);
        }

        static::creating(function ($model) {
            if ($model->branch_id) {
                return;
            }

            $shopId = $model->shop_id ?? (Auth::check() ? Auth::user()->shop_id : null);

            if ($shopId) {
                $model->branch_id = BranchContext::current()->recordingBranchIdFor((int) $shopId);
            }
        });
    }

    /**
     * Whether BranchScope is a global scope on this model. A model whose
     * rows also serve a shop-wide purpose (Stock-In rows are the shared FIFO
     * batch pool) overrides this to false and filters its lists with
     * scopeInCurrentBranch() instead.
     */
    public static function appliesBranchScope(): bool
    {
        return true;
    }

    /** The explicit form of BranchScope, for models that don't apply it globally. */
    public function scopeInCurrentBranch(Builder $query): Builder
    {
        $branchId = BranchContext::current()->scopeBranchId();

        return $branchId === null ? $query : $query->where($this->qualifyColumn('branch_id'), $branchId);
    }

    public function initializeBelongsToBranch(): void
    {
        $this->mergeFillable(['branch_id']);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
