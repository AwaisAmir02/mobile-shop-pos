<?php

namespace App\Models\Scopes;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows a transactional model to one branch: a branch-restricted staff
 * member's own branch, or the branch an unrestricted user picked in the
 * switcher. No-op for single-branch shops, for "All branches", and while
 * BranchContext is running shop-wide (balances and dues).
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $branchId = BranchContext::current()->scopeBranchId();

        if ($branchId !== null) {
            $builder->where($model->qualifyColumn('branch_id'), $branchId);
        }
    }
}
