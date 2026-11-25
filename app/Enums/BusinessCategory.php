<?php

namespace App\Enums;

/**
 * The specific kind of business, chosen at signup under a BusinessGroup.
 *
 * Bakery and the old catch-all OnlineSeller are kept for shops that already
 * have them, but signup no longer offers them: see BusinessGroup::signupCases().
 */
enum BusinessCategory: string
{
    // Shops (Retail)
    case MobileElectronics = 'mobile_electronics';
    case Kiryana = 'kiryana';
    case Pharmacy = 'pharmacy';
    case Clothing = 'clothing';
    case Other = 'other';

    // Wholesale / Distribution
    case Wholesaler = 'wholesaler';
    case Trader = 'trader';
    case Distributor = 'distributor';

    // Online sellers
    case SocialSeller = 'social_seller';
    case DarazSeller = 'daraz_seller';
    case OnlineBrand = 'online_brand';

    // Not offered at signup any more
    case Bakery = 'bakery';
    case OnlineSeller = 'online_seller';

    public function label(): string
    {
        return match ($this) {
            self::MobileElectronics => 'Mobile & electronics shop',
            self::Kiryana => 'Kiryana / general store',
            self::Pharmacy => 'Pharmacy / medical store',
            self::Clothing => 'Clothing & shoes',
            self::Other => 'Other shop',
            self::Wholesaler => 'Wholesaler',
            self::Trader => 'Trader',
            self::Distributor => 'Distributor',
            self::SocialSeller => 'Facebook / Instagram / WhatsApp seller',
            self::DarazSeller => 'Daraz seller',
            self::OnlineBrand => 'Small online brand',
            self::Bakery => 'Bakery & sweets',
            self::OnlineSeller => 'Online seller (Daraz / social media)',
        };
    }

    public function group(): ?BusinessGroup
    {
        return BusinessGroup::of($this);
    }

    public function showsMobileModules(): bool
    {
        return $this === self::MobileElectronics;
    }

    /**
     * Grocers and pharmacies sell goods that expire, so they start with expiry
     * tracking on (soonest-expiry sells first, expired stock can't be sold).
     * Only ever switched on by the defaults, never off.
     */
    public function tracksExpiryByDefault(): bool
    {
        return in_array($this, [self::Kiryana, self::Pharmacy], true);
    }

    /** What the second, lower price is called in this trade (PriceTiers). */
    public function tradePriceLabel(): string
    {
        return match ($this) {
            self::Distributor => 'Trade price',
            self::Trader => 'Wholesale rate',
            default => 'Wholesale price',
        };
    }

    /**
     * The Wholesale P2 tools each trade uses, in one place so widening one is a
     * one-line change: cheques (W6), sales orders + load sheets (W7), routes +
     * bookers (W8), schemes + claims (W9), bonus quantity on Stock In (W9),
     * purchase returns (W10), contra with a linked vendor (W11).
     *
     * @return list<string>
     */
    public function wholesaleTools(): array
    {
        return match ($this) {
            self::Wholesaler => ['cheques', 'returns', 'bonus'],
            self::Trader => ['cheques', 'returns', 'bonus', 'contra'],
            self::Distributor => ['cheques', 'returns', 'bonus', 'orders', 'routes', 'schemes'],
            default => [],
        };
    }

    /** Pharmacies record each medicine's generic (salt) name and DRAP registration number. */
    public function usesMedicineFields(): bool
    {
        return $this === self::Pharmacy;
    }

    public function starterCategories(): array
    {
        return match ($this) {
            self::Kiryana => ['Grocery', 'Household'],
            self::Bakery => ['Bakery', 'Sweets'],
            self::Clothing => ['Men', 'Women', 'Kids'],
            self::Pharmacy => ['Medicines', 'Personal care'],
            default => [],
        };
    }
}
