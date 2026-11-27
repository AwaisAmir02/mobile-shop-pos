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

    // Services & appointments
    case RepairWorkshop = 'repair_workshop';
    case Salon = 'salon';
    case Spa = 'spa';
    case Gym = 'gym';
    case PersonalTraining = 'personal_training';

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
            self::RepairWorkshop => 'Repair workshop (AC, bike, auto, appliances)',
            self::Salon => 'Salon / barber',
            self::Spa => 'Spa / beauty clinic',
            self::Gym => 'Gym / fitness club',
            self::PersonalTraining => 'Personal training / fitness studio',
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
     * The Wholesale P2 tools each trade uses (WholesaleTool), in one place so
     * widening one is a one-line change. Every other trade gets none.
     *
     * @return list<WholesaleTool>
     */
    public function wholesaleTools(): array
    {
        return match ($this) {
            self::Wholesaler => [WholesaleTool::Cheques, WholesaleTool::PurchaseReturns, WholesaleTool::BonusQuantity],
            self::Trader => [WholesaleTool::Cheques, WholesaleTool::PurchaseReturns, WholesaleTool::BonusQuantity, WholesaleTool::Contra],
            self::Distributor => [WholesaleTool::Cheques, WholesaleTool::PurchaseReturns, WholesaleTool::BonusQuantity, WholesaleTool::SalesOrders, WholesaleTool::Routes, WholesaleTool::Schemes],
            default => [],
        };
    }

    /**
     * The Online P1 tools each online trade uses (OnlineTool), in one place so
     * widening one (say, delivery charges for a kiryana) is a one-line change.
     * Every other trade gets none.
     *
     * @return list<OnlineTool>
     */
    public function onlineTools(): array
    {
        return match ($this) {
            self::SocialSeller, self::DarazSeller, self::OnlineBrand => OnlineTool::cases(),
            default => [],
        };
    }

    /**
     * The Services group's tools each trade uses (ServiceTradeTool), in one place.
     * A shop that also runs a related trade (business_addons) gets both lists:
     * Shop::usesTool() merges them. Every other trade gets none.
     *
     * @return list<ServiceTradeTool>
     */
    public function serviceTradeTools(): array
    {
        $beauty = [ServiceTradeTool::ServiceMenu, ServiceTradeTool::Appointments, ServiceTradeTool::WalkIns, ServiceTradeTool::StaffEarnings, ServiceTradeTool::Memberships, ServiceTradeTool::GiftVouchers, ServiceTradeTool::Deposits, ServiceTradeTool::ClientNotes, ServiceTradeTool::Broadcasts, ServiceTradeTool::StaffAttendance];
        $fitness = [ServiceTradeTool::ServiceMenu, ServiceTradeTool::Appointments, ServiceTradeTool::StaffEarnings, ServiceTradeTool::Memberships, ServiceTradeTool::CheckIns, ServiceTradeTool::ClientNotes, ServiceTradeTool::Broadcasts, ServiceTradeTool::StaffAttendance];

        return match ($this) {
            self::RepairWorkshop => [ServiceTradeTool::JobCards, ServiceTradeTool::ServiceMenu, ServiceTradeTool::Deposits, ServiceTradeTool::StaffEarnings, ServiceTradeTool::ClientNotes, ServiceTradeTool::StaffAttendance],
            self::Salon, self::Spa => $beauty,
            self::Gym => [...$fitness, ServiceTradeTool::Biometric],
            self::PersonalTraining => $fitness,
            default => [],
        };
    }

    /**
     * Related trades one shop may also run (a salon that is also a spa, a gym
     * with personal training). Only these pairs combine; nothing else does.
     *
     * @return list<self>
     */
    public function combinesWith(): array
    {
        return match ($this) {
            self::Salon => [self::Spa],
            self::Spa => [self::Salon],
            self::Gym => [self::PersonalTraining],
            self::PersonalTraining => [self::Gym],
            default => [],
        };
    }

    /**
     * The values in $addons this trade may combine with, in order, once each.
     *
     * @return list<string>
     */
    public function cleanAddons(array $addons): array
    {
        $allowed = array_map(fn (self $c) => $c->value, $this->combinesWith());

        return array_values(array_unique(array_filter(array_map('strval', $addons), fn ($v) => in_array($v, $allowed, true))));
    }

    /** @return list<string> service categories a new service business starts with */
    public function starterServiceCategories(): array
    {
        return match ($this) {
            self::RepairWorkshop => ['Labour', 'Site visits'],
            self::Salon => ['Hair', 'Skin', 'Nails', 'Makeup'],
            self::Spa => ['Massage', 'Skin', 'Body'],
            self::Gym => ['Memberships', 'Personal training', 'Classes'],
            self::PersonalTraining => ['Personal training', 'Classes'],
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
            self::RepairWorkshop => ['Spare parts'],
            self::Salon, self::Spa => ['Retail products'],
            self::Gym, self::PersonalTraining => ['Supplements', 'Accessories'],
            default => [],
        };
    }
}
