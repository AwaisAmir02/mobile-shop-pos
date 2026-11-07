<?php

namespace App\Models\Scopes;

use App\Support\ApiShopContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class ShopScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (($apiShopId = ApiShopContext::current()->shopId()) !== null) {
            $builder->where($model->qualifyColumn('shop_id'), $apiShopId);

            return;
        }

        if (Auth::check() && Auth::user()->shop_id) {
            $builder->where($model->qualifyColumn('shop_id'), Auth::user()->shop_id);
        }
    }
}
