<?php

namespace App\Models;

use App\Enums\PlanType;
use App\Enums\ShopScreen;
use App\Enums\SubscriptionStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    protected $attributes = [
        'subscription_status' => 'inactive',
    ];

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'plan_type',
        'subscription_status',
        'subscription_start_date',
        'products_allowed',
        'sales_allowed',
        'disabled_screens',
        'sim_sale_commission_percent',
        'balance_load_commission_percent',
        'wallet_load_commission_percent',
        'bills_commission_percent',
        'nadra_verification_commission_percent',
    ];

    protected function casts(): array
    {
        return [
            'plan_type' => PlanType::class,
            'subscription_status' => SubscriptionStatus::class,
            'subscription_start_date' => 'date',
            'disabled_screens' => 'array',
            'sim_sale_commission_percent' => 'decimal:2',
            'balance_load_commission_percent' => 'decimal:2',
            'wallet_load_commission_percent' => 'decimal:2',
            'bills_commission_percent' => 'decimal:2',
            'nadra_verification_commission_percent' => 'decimal:2',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class)->where('is_owner', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function udhaarTransactions(): HasMany
    {
        return $this->hasMany(UdhaarTransaction::class);
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class);
    }

    /**
     * Super Admin's platform-level override: a screen disabled here is
     * unreachable for every user of this shop, owner included, regardless
     * of their own role permissions. Checked first by User::hasAccessTo().
     */
    public function isScreenDisabled(ShopScreen|string $screen): bool
    {
        $value = $screen instanceof ShopScreen ? $screen->value : $screen;

        return in_array($value, $this->disabled_screens ?? [], true);
    }

    public function expectedRenewalDate(): ?Carbon
    {
        if (! $this->subscription_start_date || ! $this->plan_type) {
            return null;
        }

        return match ($this->plan_type) {
            PlanType::Monthly => $this->subscription_start_date->copy()->addMonth(),
            PlanType::Yearly => $this->subscription_start_date->copy()->addYear(),
        };
    }

    public function isSubscriptionActive(): bool
    {
        return $this->subscription_status === SubscriptionStatus::Active;
    }
}
