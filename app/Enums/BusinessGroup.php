<?php

namespace App\Enums;

/**
 * Step 1 of the signup business picker. Only groups with real feature support
 * are listed; Food, Education, Company and Clinic/Lab are left out until built.
 * Service businesses are "service_trades", not "services": that name is the
 * mobile shop's Services module, feature and route.
 * Nothing is stored for the group: the shop keeps only its BusinessCategory.
 */
enum BusinessGroup: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Online = 'online';
    case ServiceTrades = 'service_trades';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Shops (Retail)',
            self::Wholesale => 'Wholesale / Distribution',
            self::Online => 'Online seller',
            self::ServiceTrades => 'Services & appointments',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Retail => 'You sell to customers at a counter.',
            self::Wholesale => 'You sell in bulk to shops and other businesses.',
            self::Online => 'You sell through social media, Daraz or your own site.',
            self::ServiceTrades => 'A workshop, salon, spa, gym or trainer: you sell your time and work.',
        };
    }

    /** @return list<BusinessCategory> the sub-types offered at signup, in display order */
    public function signupCases(): array
    {
        return match ($this) {
            self::Retail => [BusinessCategory::MobileElectronics, BusinessCategory::Kiryana, BusinessCategory::Pharmacy, BusinessCategory::Clothing, BusinessCategory::Other],
            self::Wholesale => [BusinessCategory::Wholesaler, BusinessCategory::Trader, BusinessCategory::Distributor],
            self::Online => [BusinessCategory::SocialSeller, BusinessCategory::DarazSeller, BusinessCategory::OnlineBrand],
            self::ServiceTrades => [BusinessCategory::RepairWorkshop, BusinessCategory::Salon, BusinessCategory::Spa, BusinessCategory::Gym, BusinessCategory::PersonalTraining],
        };
    }

    /** The group a sub-type is offered under, or null for one signup no longer offers. */
    public static function of(BusinessCategory $category): ?self
    {
        foreach (self::cases() as $group) {
            if (in_array($category, $group->signupCases(), true)) {
                return $group;
            }
        }

        return null;
    }

    /** @return list<string> every sub-type value signup accepts */
    public static function signupValues(): array
    {
        return array_map(fn (BusinessCategory $c) => $c->value, array_merge(...array_map(fn (self $g) => $g->signupCases(), self::cases())));
    }
}
