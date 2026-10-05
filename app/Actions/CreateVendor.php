<?php

namespace App\Actions;

use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CreateVendor
{
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('vendors', 'name')->where('shop_id', Auth::user()?->shop_id)->ignore($ignoreId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function handle(string $name, ?string $phone = null, ?string $companyName = null, ?string $address = null, ?string $notes = null): Vendor
    {
        return Vendor::create([
            'name' => trim($name),
            'phone' => $phone !== '' && $phone !== null ? $phone : null,
            'company_name' => $companyName !== '' && $companyName !== null ? $companyName : null,
            'address' => $address !== '' && $address !== null ? $address : null,
            'notes' => $notes !== '' && $notes !== null ? $notes : null,
        ]);
    }
}
