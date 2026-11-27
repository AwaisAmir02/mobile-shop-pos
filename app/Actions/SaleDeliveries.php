<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleDelivery;
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

/**
 * Where an online order goes (O1): the name, phone and address an online seller
 * takes from a message or a call, saved with the sale so the parcel is booked
 * from it and refusals can be found by phone (ParcelRefusals).
 */
class SaleDeliveries
{
    /** @return array<string, array<int, string>> */
    public static function rules(string $prefix = 'delivery.'): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:100'],
            $prefix.'phone' => ['required', 'string', 'max:20', fn ($attribute, $value, $fail) => PhoneNumber::isPakistaniMobile((string) $value) ? null : $fail('Enter a mobile number like 0300 1234567.')],
            $prefix.'address' => ['required', 'string', 'min:10', 'max:300'],
            $prefix.'city' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** @param  array{name: string, phone: string, address: string, city?: ?string}  $details */
    public static function save(Sale $sale, array $details): SaleDelivery
    {
        if (blank($details['name'] ?? null) || blank($details['phone'] ?? null) || blank($details['address'] ?? null)) {
            throw ValidationException::withMessages(['delivery.address' => 'A delivery needs a name, a phone number and an address.']);
        }

        return SaleDelivery::updateOrCreate(['sale_id' => $sale->id], [
            'shop_id' => $sale->shop_id,
            'name' => mb_substr(trim($details['name']), 0, 100),
            'phone' => mb_substr(trim($details['phone']), 0, 20),
            'address' => mb_substr(trim($details['address']), 0, 300),
            'city' => filled($details['city'] ?? null) ? mb_substr(trim($details['city']), 0, 60) : null,
        ]);
    }

    /**
     * The shop's customer with this phone, else a new one from the delivery
     * details: a buyer who messages twice is one customer, not two.
     *
     * @param  array{name: string, phone: string, address: string, city?: ?string}  $details
     */
    public static function customerFor(int $shopId, array $details): Customer
    {
        $key = PhoneNumber::normalize($details['phone'] ?? null);

        if ($key !== null) {
            // Narrowed in SQL by the last ten digits, however the phone was typed; PHP decides.
            $match = Customer::withoutGlobalScopes()->where('shop_id', $shopId)
                ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?", ['%'.substr($key, -10)])
                ->orderBy('id')->get(['id', 'phone'])
                ->first(fn (Customer $customer) => PhoneNumber::normalize($customer->phone) === $key);

            if ($match !== null) {
                return Customer::withoutGlobalScopes()->findOrFail($match->id);
            }
        }

        $address = trim(implode(', ', array_filter([trim((string) ($details['address'] ?? '')), trim((string) ($details['city'] ?? ''))])));

        return CreateCustomer::handle(trim($details['name']), trim($details['phone']), $address !== '' ? $address : null);
    }
}
