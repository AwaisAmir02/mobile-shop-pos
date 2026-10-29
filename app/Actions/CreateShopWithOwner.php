<?php

namespace App\Actions;

use App\Enums\BusinessCategory;
use App\Enums\SubscriptionStatus;
use App\Models\Shop;
use App\Models\User;
use App\Services\BusinessCategories;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateShopWithOwner
{
    public static function shopRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'business_category' => ['nullable', Rule::enum(BusinessCategory::class)],
        ];
    }

    public static function ownerRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @param  array{name: string, phone?: ?string, email?: ?string, address?: ?string}  $shop
     * @param  array{name: string, email?: ?string, phone?: ?string, password?: string, password_hash?: string, phone_verified?: bool}  $owner
     * @param  array{plan_id?: ?int, plan_type: string, subscription_status?: SubscriptionStatus|string, subscription_start_date: string, products_allowed?: ?int, sales_allowed?: ?int}  $subscription
     * @return array{0: Shop, 1: User}
     */
    public static function handle(array $shop, array $owner, array $subscription, bool $emailVerified): array
    {
        return DB::transaction(function () use ($shop, $owner, $subscription, $emailVerified) {
            $status = $subscription['subscription_status'] ?? SubscriptionStatus::Active;

            $created = Shop::create([
                'name' => $shop['name'],
                'phone' => self::blankToNull($shop['phone'] ?? null),
                'email' => self::blankToNull($shop['email'] ?? null),
                'address' => self::blankToNull($shop['address'] ?? null),
                'business_category' => self::blankToNull($shop['business_category'] ?? null),
                'plan_id' => $subscription['plan_id'] ?? null,
                'plan_type' => $subscription['plan_type'],
                'subscription_status' => $status instanceof SubscriptionStatus ? $status->value : $status,
                'subscription_start_date' => $subscription['subscription_start_date'],
                'products_allowed' => $subscription['products_allowed'] ?? null,
                'sales_allowed' => $subscription['sales_allowed'] ?? null,
                'limit_overrides' => array_map('intval', array_filter([
                    'products' => $subscription['products_allowed'] ?? null,
                    'sales_per_month' => $subscription['sales_allowed'] ?? null,
                ], fn ($value) => $value !== null && $value !== '')) ?: null,
            ]);

            $user = User::create([
                'shop_id' => $created->id,
                'name' => $owner['name'],
                'email' => $owner['email'] ?? null,
                'phone' => $owner['phone'] ?? null,
                'password' => $owner['password_hash'] ?? (isset($owner['password']) ? Hash::make($owner['password']) : null),
                'is_owner' => true,
            ]);

            $verified = array_filter([
                'email_verified_at' => $emailVerified && $user->email ? now() : null,
                'phone_verified_at' => ($owner['phone_verified'] ?? false) && $user->phone ? now() : null,
            ]);

            if ($verified !== []) {
                $user->forceFill($verified)->save();
            }

            app(BusinessCategories::class)->applyDefaults($created);

            return [$created->fresh(), $user];
        });
    }

    protected static function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
