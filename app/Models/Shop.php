<?php

namespace App\Models;

use App\Enums\PlanType;
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
    ];

    protected function casts(): array
    {
        return [
            'plan_type' => PlanType::class,
            'subscription_status' => SubscriptionStatus::class,
            'subscription_start_date' => 'date',
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
