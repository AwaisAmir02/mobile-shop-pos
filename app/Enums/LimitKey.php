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
    case WhatsAppOrdersPerMonth = 'whatsapp_orders_per_month';
    case OnlineOrdersPerMonth = 'online_orders_per_month';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Products',
            self::StaffUsers => 'Staff users',
            self::SalesPerMonth => 'Sales this month',
            self::WhatsAppMessagesPerMonth => 'WhatsApp messages this month',
            self::MarketingPostsPerMonth => 'Marketing posts this month',
            self::Branches => 'Branches',
            self::WhatsAppOrdersPerMonth => 'WhatsApp orders this month',
            self::OnlineOrdersPerMonth => 'Online orders this month',
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
                self::WhatsAppOrdersPerMonth, self::OnlineOrdersPerMonth => 'order',
            };
        }

        return match ($this) {
            self::Products => 'products',
            self::StaffUsers => 'staff users',
            self::SalesPerMonth => 'sales',
            self::WhatsAppMessagesPerMonth => 'messages',
            self::MarketingPostsPerMonth => 'posts',
            self::Branches => 'branches',
            self::WhatsAppOrdersPerMonth, self::OnlineOrdersPerMonth => 'orders',
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

    /**
     * The amount as the pricing page says it: "500 products", "1,500 sales a
     * month", "Unlimited branches". Null is unlimited.
     */
    public function amountLabel(?int $limit): string
    {
        if ($limit === null) {
            return 'Unlimited '.$this->unit();
        }

        if ($limit === 0 && $this === self::StaffUsers) {
            return 'Owner login only';
        }

        return number_format($limit).' '.$this->unit($limit).($this->isMonthly() ? ' a month' : '');
    }

    public function isMonthly(): bool
    {
        return in_array($this, [self::SalesPerMonth, self::WhatsAppMessagesPerMonth, self::MarketingPostsPerMonth, self::WhatsAppOrdersPerMonth, self::OnlineOrdersPerMonth], true);
    }

    public function blocksAtLimit(): bool
    {
        return $this !== self::SalesPerMonth;
    }

    /** The lowest limit a plan or a shop override can set. Main always counts as one branch. */
    public function minimum(): int
    {
        return $this === self::Branches ? 1 : 0;
    }

    /**
     * The shops column this limit was kept in before limit_overrides. The
     * admin shop page still reads it as a fallback and writes it alongside.
     */
    public function legacyColumn(): ?string
    {
        return match ($this) {
            self::Products => 'products_allowed',
            self::SalesPerMonth => 'sales_allowed',
            default => null,
        };
    }

    /**
     * Branch packs are the one recurring kind of limit pack: a branch is a
     * standing thing, so its pack must not lapse when the plan renews.
     * Every other pack stays one-off, until the plan renews.
     */
    public function packRecurs(): bool
    {
        return $this === self::Branches;
    }

    /** The feature this limit is shown with in the Plan editor, if any. */
    public function feature(): ?FeatureKey
    {
        return collect(FeatureKey::cases())->first(fn (FeatureKey $feature) => $feature->limit() === $this);
    }

    /**
     * Limits no feature is paired with: the Plan editor's "Core limits".
     *
     * @return array<int, self>
     */
    public static function core(): array
    {
        return array_values(array_filter(self::cases(), fn (self $key) => $key->feature() === null));
    }
}
