<?php

namespace App\Enums;

enum FeatureKey: string
{
    case WhatsAppOrdering = 'whatsapp_ordering';
    case WhatsAppMessaging = 'whatsapp_messaging';
    case Social = 'social';
    case Ads = 'ads';
    case Import = 'import';
    case Barcode = 'barcode';
    case Branding = 'branding';
    case Services = 'services';

    /** The order of the switches on the admin shop page; a case not listed goes last. */
    protected const ADMIN_ORDER = [self::Barcode, self::Branding, self::Services, self::WhatsAppOrdering, self::WhatsAppMessaging, self::Social, self::Ads];

    public function label(): string
    {
        return match ($this) {
            self::WhatsAppOrdering => 'WhatsApp ordering',
            self::WhatsAppMessaging => 'WhatsApp messaging',
            self::Social => 'Facebook & Instagram posting',
            self::Ads => 'Meta ads',
            self::Import => 'Excel product import',
            self::Barcode => 'Barcodes',
            self::Branding => 'Invoice branding',
            self::Services => 'Services module',
        };
    }

    public function column(): string
    {
        return $this->value.'_enabled';
    }

    /**
     * The usage limit that goes with this feature, if it has one. The Plan
     * editor shows it on the feature's row; limits no feature names stay
     * under "Core limits". Pairing is display only: a limit applies whether
     * or not its feature is on.
     */
    public function limit(): ?LimitKey
    {
        return match ($this) {
            self::WhatsAppOrdering => LimitKey::WhatsAppOrdersPerMonth,
            self::WhatsAppMessaging => LimitKey::WhatsAppMessagesPerMonth,
            self::Social => LimitKey::MarketingPostsPerMonth,
            default => null,
        };
    }

    /**
     * The admin shop page's switch for this feature.
     *
     * @return array{label: string, description: string, toast: string}
     */
    public function adminSwitch(): array
    {
        [$label, $description, $toast] = match ($this) {
            self::WhatsAppOrdering => ['WhatsApp Ordering', "Chatbot ordering and a Pending Orders inbox on the shop's own WhatsApp Business number. Off by default.", 'WhatsApp ordering'],
            self::WhatsAppMessaging => ['WhatsApp Messaging', "Payment reminders, repair-ready notices, restock alerts and review requests sent from the shop's own WhatsApp Business number. Off by default.", 'WhatsApp messaging'],
            self::Social => ['Social Posting', "Post products to the shop's own Facebook Page and Instagram, and run Meta ads. Off by default.", 'Social posting'],
            self::Ads => ['Meta Ads', 'Lets the shop create and launch paid Meta ads from its own ad account (the shop pays Meta directly). Off by default.', 'Meta ads'],
            self::Import => ['Products Import', 'A separate, opt-in capability — off by default for every shop, and no one at the shop (owner included) can turn it on for themselves. Lets the Products screen import a catalogue from an Excel/CSV sheet.', 'Products Import'],
            self::Barcode => ['Barcodes', 'Barcode field, generation, label printing and sale scanning. Stored barcodes are kept while off.', 'Barcodes'],
            self::Branding => ['Branding', 'Lets the shop upload its own logo and set a display name, shown on its receipts and invoices in place of the plain shop name.', 'Custom branding'],
            self::Services => ['Services', 'A sellable catalog of services, installations and downloads — separate from Products and Stock In. Off by default for every shop.', 'Services'],
        };

        return ['label' => $label, 'description' => $description, 'toast' => $toast];
    }

    /**
     * The features listed under "Shop Features" on the admin shop page.
     * Import has a card of its own above Module Access.
     *
     * @return array<int, self>
     */
    public static function adminSwitches(): array
    {
        return collect(self::cases())
            ->reject(fn (self $feature) => $feature === self::Import)
            ->sortBy(fn (self $feature) => ($position = array_search($feature, self::ADMIN_ORDER, true)) === false ? PHP_INT_MAX : $position)
            ->values()
            ->all();
    }
}
