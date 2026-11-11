<?php

namespace App\Enums;

/** Where a shop on automatic billing is in its cycle. See BillingCycle::phase(). */
enum BillingPhase: string
{
    /** Not on automatic billing: an admin manages it by hand and it is never locked. */
    case Manual = 'manual';
    case Trial = 'trial';
    /** The trial is over and the first payment hasn't been confirmed. Locked. */
    case TrialEnded = 'trial_ended';
    case Active = 'active';
    /** Paid, but the period ends within the notice window. */
    case EndingSoon = 'ending_soon';
    /** The paid period is over and the renewal hasn't been confirmed. Locked. */
    case Lapsed = 'lapsed';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Managed by HisabOKitab',
            self::Trial => 'Free trial',
            self::TrialEnded => 'Trial ended — unpaid',
            self::Active => 'Active',
            self::EndingSoon => 'Ending soon',
            self::Lapsed => 'Ended — unpaid',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Manual => 'neutral',
            self::Trial => 'brand',
            self::Active => 'success',
            self::EndingSoon => 'warning',
            self::TrialEnded, self::Lapsed => 'danger',
        };
    }

    public function locks(): bool
    {
        return $this === self::TrialEnded || $this === self::Lapsed;
    }
}
