<?php

namespace App\Enums;

enum LimitKey: string
{
    case Products = 'products';
    case StaffUsers = 'staff_users';
    case SalesPerMonth = 'sales_per_month';
    case WhatsAppMessagesPerMonth = 'whatsapp_messages_per_month';
    case MarketingPostsPerMonth = 'marketing_posts_per_month';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Products',
            self::StaffUsers => 'Staff users',
            self::SalesPerMonth => 'Sales this month',
            self::WhatsAppMessagesPerMonth => 'WhatsApp messages this month',
            self::MarketingPostsPerMonth => 'Marketing posts this month',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Products => 'products',
            self::StaffUsers => 'staff users',
            self::SalesPerMonth => 'sales',
            self::WhatsAppMessagesPerMonth => 'messages',
            self::MarketingPostsPerMonth => 'posts',
        };
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
