<?php

namespace App\Actions;

use App\Models\Customer;

class CreateCustomer
{
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function handle(string $name, ?string $phone = null, ?string $address = null): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'address' => $address !== '' ? $address : null,
        ]);
    }
}
