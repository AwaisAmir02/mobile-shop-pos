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
    case Variants = 'variants';
    case MeasuredUnits = 'measured_units';
    case ExpiryTracking = 'expiry_tracking';
    case PurchaseOrders = 'purchase_orders';
    case Payroll = 'payroll';
    case Ledger = 'ledger';
    case OnlineStore = 'online_store';

    /** The order of the switches on the admin shop page; a case not listed goes last. */
    protected const ADMIN_ORDER = [self::Barcode, self::Variants, self::MeasuredUnits, self::ExpiryTracking, self::PurchaseOrders, self::Payroll, self::Ledger, self::Branding, self::Services, self::WhatsAppOrdering, self::WhatsAppMessaging, self::OnlineStore, self::Social, self::Ads];

    /**
     * Whether plans and add-ons decide this feature. One that isn't is free
     * and set per shop from the admin shop page alone: the Plan editor saves
     * every feature it lists, so listing one there would switch it off for
     * the plan's shops the next time the plan is saved.
     */
    public function planControlled(): bool
    {
        return ! in_array($this, [self::Variants, self::MeasuredUnits, self::ExpiryTracking, self::Ledger], true);
    }

    /** @return array<int, self> */
    public static function planCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature) => $feature->planControlled()));
    }

    /**
     * Plan features in the order a customer compares them on the pricing
     * page: selling and marketing first, back office next, extras last.
     *
     * @return array<int, self>
     */
    public static function pricingOrder(): array
    {
        $order = [self::WhatsAppOrdering, self::WhatsAppMessaging, self::Social, self::Ads, self::OnlineStore, self::PurchaseOrders, self::Payroll, self::Services, self::Barcode, self::Import, self::Branding];

        return collect(self::planCases())
            ->sortBy(fn (self $feature) => ($position = array_search($feature, $order, true)) === false ? PHP_INT_MAX : $position)
            ->values()
            ->all();
    }

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
            self::Variants => 'Product variants',
            self::MeasuredUnits => 'Weighed and measured items',
            self::ExpiryTracking => 'Expiry date tracking',
            self::PurchaseOrders => 'Purchase orders & goods receipts',
            self::Payroll => 'Payroll',
            self::Ledger => 'Double-entry ledger',
            self::OnlineStore => 'Online store connection',
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
            self::OnlineStore => LimitKey::OnlineOrdersPerMonth,
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
            self::Variants => ['Product Variants', 'Sell one product in several sizes or colours, each with its own stock, barcode and price. A group counts as one product on the plan. Free, off by default. Existing groups keep selling while off.', 'Product variants'],
            self::MeasuredUnits => ['Weighed & Measured Items', 'Sell products by kg, litre, metre, yard or gaz, with quantities like 1.5. Free, off by default. Products already using a unit keep selling while off. Not offered in the WhatsApp chatbot.', 'Weighed and measured items'],
            self::ExpiryTracking => ['Expiry Date Tracking', 'Record expiry dates on stock. The batch closest to expiry sells first, its price and cost follow that batch, and expired stock can no longer be sold. Free, off by default. While off, stock sells oldest first and any dates are ignored.', 'Expiry date tracking'],
            self::PurchaseOrders => ['Purchase Orders', 'Order from vendors, then receive goods against the order, including part deliveries. Plain Stock In keeps working alongside it. When off, existing orders are read-only and vendor balances are unaffected.', 'Purchase orders'],
            self::Payroll => ['Payroll', 'Staff records, salary advances, monthly payslips and payslip PDFs. Paid salaries appear in Expenses. When off, history stays readable and no new payroll can be run.', 'Payroll'],
            self::Ledger => ['Double-Entry Ledger', 'Posts the shop\'s records to a journal for a Trial Balance. Existing screens, dues and reports are unaffected. Off by default. Turning it off freezes the ledger read-only, and restarting needs a new setup.', 'Double-entry ledger'],
            self::OnlineStore => ['Online Store', 'Lets the shop connect its own online store through the API: publish products and live stock, and receive orders. When off, connections pause and the store is told to stop selling. Stock and sales are unaffected.', 'Online store'],
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
