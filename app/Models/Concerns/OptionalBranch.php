<?php

namespace App\Models\Concerns;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * For a record that may belong to no branch at all, meaning the whole shop.
 * Use alongside BelongsToBranch; the class must also define its own empty
 * bootBelongsToBranch(), so an empty branch_id stays empty rather than being
 * filled in with the current branch.
 */
trait OptionalBranch
{
    /** Branch-restricted staff see only their branch; anyone else also sees the shop-wide records. */
    public function scopeInBranchOrShared(Builder $query): Builder
    {
        $context = BranchContext::current();
        $branchId = $context->scopeBranchId();

        if ($branchId === null) {
            return $query;
        }

        $column = $this->qualifyColumn('branch_id');

        return $context->restrictedBranchId()
            ? $query->where($column, $branchId)
            : $query->where(fn (Builder $q) => $q->where($column, $branchId)->orWhereNull($column));
    }
}
