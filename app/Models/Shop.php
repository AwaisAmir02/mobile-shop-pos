<?php

namespace App\Models;

use App\Enums\BusinessCategory;
use App\Enums\PlanType;
use App\Enums\ShopScreen;
use App\Enums\SubscriptionStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

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
        'plan_id',
        'subscription_status',
        'subscription_start_date',
        'products_allowed',
        'sales_allowed',
        'limit_overrides',
        'feature_overrides',
        'disabled_screens',
        'hidden_screens',
        'business_category',
        'import_enabled',
        'barcode_enabled',
        'branding_enabled',
        'invoice_display_name',
        'invoice_address',
        'logo_path',
        'services_enabled',
        'whatsapp_enabled',
        'social_enabled',
        'ads_enabled',
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
            'hidden_screens' => 'array',
            'business_category' => BusinessCategory::class,
            'import_enabled' => 'boolean',
            'barcode_enabled' => 'boolean',
            'branding_enabled' => 'boolean',
            'services_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'social_enabled' => 'boolean',
            'ads_enabled' => 'boolean',
            'limit_overrides' => 'array',
            'feature_overrides' => 'array',
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

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ShopAddon::class);
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

    public function isScreenHidden(ShopScreen|string $screen): bool
    {
        $value = $screen instanceof ShopScreen ? $screen->value : $screen;

        return in_array($value, $this->hidden_screens ?? [], true);
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

    /**
     * Branding-aware on purpose: a shop with branding_enabled off must show
     * its plain name even if invoice_display_name is still sitting in the
     * column from a previous opt-in — zero behavior change while off.
     */
    public function invoiceDisplayName(): string
    {
        if ($this->branding_enabled && $this->invoice_display_name) {
            return $this->invoice_display_name;
        }

        return $this->name;
    }

    public function invoiceAddress(): ?string
    {
        if ($this->branding_enabled && $this->invoice_address) {
            return $this->invoice_address;
        }

        return null;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * DomPDF can't fetch our own /storage URLs back over HTTP, so the logo
     * is embedded as a base64 data URI, matching the pattern already used
     * for HisabOKitab's own logo in SubscriptionInvoicePdfService.
     */
    public function logoDataUri(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($this->logo_path)) {
            return null;
        }

        $mime = $disk->mimeType($this->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($this->logo_path));
    }
}
