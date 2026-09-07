<?php

namespace App\Actions;

use App\Models\BillCategory;
use Illuminate\Validation\Rule;

class CreateBillCategory
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('bill_categories', 'name')->where('shop_id', $shopId)],
        ];
    }

    public static function handle(string $name): BillCategory
    {
        return BillCategory::create(['name' => $name]);
    }
}
