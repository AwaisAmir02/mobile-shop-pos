<?php

namespace App\Actions;

use App\Models\BillProvider;
use Illuminate\Validation\Rule;

class CreateBillProvider
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'billCategoryId' => ['required', 'integer', Rule::exists('bill_categories', 'id')->where('shop_id', $shopId)],
            'region' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function handle(string $name, int $billCategoryId, ?string $region = null): BillProvider
    {
        return BillProvider::create([
            'name' => $name,
            'bill_category_id' => $billCategoryId,
            'region' => $region,
        ]);
    }
}
