<?php

namespace App\Actions;

use App\Models\AccessoryCategoryOption;
use Illuminate\Validation\Rule;

class CreateAccessoryCategory
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('accessory_category_options', 'name')->where('shop_id', $shopId)],
        ];
    }

    public static function handle(string $name): AccessoryCategoryOption
    {
        return AccessoryCategoryOption::create(['name' => $name]);
    }
}
