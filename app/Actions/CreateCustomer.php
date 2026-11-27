<?php

namespace App\Actions;

use App\Models\Customer;
use App\Enums\PriceTier;
use Illuminate\Validation\Rule;

class CreateCustomer
{
    /** A business customer's details, price level and credit terms (wholesale businesses); all optional. */
    public const BUSINESS_FIELDS = ['business_name', 'contact_person', 'phone_2', 'area', 'ntn', 'strn', 'cnic', 'price_tier', 'credit_limit', 'payment_terms_days'];

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function businessRules(): array
    {
        return [
            'business_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone_2' => ['nullable', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:100'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'strn' => ['nullable', 'string', 'max:30'],
            'cnic' => ['nullable', 'string', 'max:20'],
            'price_tier' => ['nullable', Rule::in([PriceTier::Normal->value])],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    /** @return array<string, string> */
    public static function businessAttributes(): array
    {
        return [
            'business_name' => 'business name',
            'contact_person' => 'contact person',
            'phone_2' => 'second phone',
            'price_tier' => 'price',
            'credit_limit' => 'credit limit',
            'payment_terms_days' => 'payment terms',
        ];
    }

    /**
     * Form input (strings) as the columns hold them: blanks become null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function businessAttributesFrom(array $input): array
    {
        $out = [];

        foreach (self::BUSINESS_FIELDS as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            $out[$field] = $value === '' ? null : $value;
        }

        $out['credit_limit'] = $out['credit_limit'] !== null ? round((float) $out['credit_limit'], 2) : null;
        $out['payment_terms_days'] = $out['payment_terms_days'] !== null && (int) $out['payment_terms_days'] > 0 ? (int) $out['payment_terms_days'] : null;
        // Only "normal" is stored; blank (null) is the default, the trade price.
        $out['price_tier'] = $out['price_tier'] === PriceTier::Normal->value ? PriceTier::Normal->value : null;

        return $out;
    }

    /** @param  array<string, mixed>  $business  businessAttributesFrom() output; empty for a shop that doesn't use them */
    public static function handle(string $name, ?string $phone = null, ?string $address = null, array $business = []): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'address' => $address !== '' ? $address : null,
        ] + $business);
    }
}
