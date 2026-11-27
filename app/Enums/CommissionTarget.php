<?php

namespace App\Enums;

/** What a commission rule pays on. The most specific rule for a line wins (StaffEarnings::ruleFor). */
enum CommissionTarget: string
{
    case Service = 'service';
    case ServiceCategory = 'service_category';
    case AllServices = 'all_services';
    case MembershipPlan = 'membership_plan';
    case AllMemberships = 'all_memberships';
    case Products = 'products';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'One service',
            self::ServiceCategory => 'A service category',
            self::AllServices => 'All services',
            self::MembershipPlan => 'One membership or package',
            self::AllMemberships => 'All memberships and packages',
            self::Products => 'Products sold',
        };
    }

    /** Higher wins when two rules match the same line. */
    public function specificity(): int
    {
        return match ($this) {
            self::Service, self::MembershipPlan => 3,
            self::ServiceCategory => 2,
            self::AllServices, self::AllMemberships, self::Products => 1,
        };
    }

    public function needsTarget(): bool
    {
        return in_array($this, [self::Service, self::ServiceCategory, self::MembershipPlan], true);
    }
}
