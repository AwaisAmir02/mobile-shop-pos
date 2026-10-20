<?php

namespace App\Actions;

use App\Models\ServiceCategory;
use Illuminate\Validation\Rule;

class CreateServiceCategory
{
    public static function rules(int $shopId): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('service_categories', 'name')->where('shop_id', $shopId)],
        ];
    }

    public static function handle(string $name): ServiceCategory
    {
        return ServiceCategory::create(['name' => $name]);
    }
}
