<?php

namespace App\Enums;

enum LimitKey: string
{
    case Products = 'products';
    case StaffUsers = 'staff_users';
    case SalesPerMonth = 'sales_per_month';
    case WhatsAppMessagesPerMonth = 'whatsapp_messages_per_month';
    case MarketingPostsPerMonth = 'marketing_posts_per_month';
    case Branches = 'branches';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Products',
            self::StaffUsers => 'Staff users',
            self::SalesPerMonth => 'Sales this month',
            self::WhatsAppMessagesPerMonth => 'WhatsApp messages this month',
            self::MarketingPostsPerMonth => 'Marketing posts this month',
            self::Branches => 'Branches',
        };
    }

    /** Singular for exactly 1 ("+1 branch"), plural otherwise ("+2 branches"). */
    public function unit(int $count = 2): string
    {
        if ($count === 1) {
            return match ($this) {
                self::Products => 'product',
                self::StaffUsers => 'staff user',
                self::SalesPerMonth => 'sale',
                self::WhatsAppMessagesPerMonth => 'message',
                self::MarketingPostsPerMonth => 'post',
                self::Branches => 'branch',
            };
        }

        return match ($this) {
            self::Products => 'products',
            self::StaffUsers => 'staff users',
            self::SalesPerMonth => 'sales',
            self::WhatsAppMessagesPerMonth => 'messages',
            self::MarketingPostsPerMonth => 'posts',
            self::Branches => 'branches',
        };
    }

    /**
     * Whether reaching this limit is shown as an alarm (red). Branches and
     * staff users are things a shop pays for and then fills on purpose, so
     * being at the limit is only a warning (amber) for those two.
     */
    public function alarmsAtLimit(): bool
    {
        return ! in_array($this, [self::Branches, self::StaffUsers], true);
    }

    public function isMonthly(): bool
    {
        return in_array($this, [self::SalesPerMonth, self::WhatsAppMessagesPerMonth, self::MarketingPostsPerMonth], true);
    }

    public function blocksAtLimit(): bool
    {
        return $this !== self::SalesPerMonth;
    }
}
