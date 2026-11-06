<?php

namespace App\Support;

/**
 * The marketing site's feature pages — the one list behind the header
 * dropdown, the mobile menu, the /features overview, the sitemap and the
 * /features/{slug} routes. All copy is reused from the original single
 * Features page and the home page, except Multi-Branch, which was written
 * new when that feature shipped (see NEW_COPY).
 */
class SiteFeatures
{
    /**
     * Pages whose copy was written after the original single Features page,
     * so it isn't in that page's text. Multi-Branch copy describes Phase 1
     * only: shared catalog and stock, per-branch records. It must not claim
     * per-branch stock or transfers until those exist.
     */
    public const NEW_COPY = ['multi-branch'];

    /**
     * @return array<string, array{
     *     name: string, summary: string, icon: string, anchor: string,
     *     eyebrow: string, title: string, lead: string, description: string,
     *     items: array<int, array{0: string, 1: string, 2: string}>,
     *     mockup: ?array{component: string, frame: string, url?: string, label: string},
     *     spotlights?: array<int, array{0: string, 1: string}>,
     *     related: array<int, string>
     * }>
     */
    public static function all(): array
    {
        return [
            'pos' => [
                'name' => 'Sales & Invoices',
                'summary' => 'Fast checkout with barcode scanning, partial payments and branded PDF invoices with a QR code.',
                'icon' => 'cart',
                'anchor' => 'counter',
                'eyebrow' => 'Counter & sales',
                'title' => 'A checkout that keeps up with a busy counter',
                'lead' => 'Scan, sell, print — and every sale already knows its cost, its customer and what is still owed.',
                'description' => 'HisabOKitab sales and invoices: barcode-first checkout, branded PDF invoices with a QR code, paid, partial or udhaar payments, and safe edits and cancellations.',
                'items' => [
                    ['cart', 'Barcode-first checkout', 'Scan or search products, adjust price and discount per line or for the whole invoice.'],
                    ['receipt', 'Branded invoices', 'PDF invoices with your shop’s name, logo and a QR code, ready to print or send on WhatsApp.'],
                    ['wallet', 'Paid, partial or udhaar', 'Take full or part payment and link the balance to the customer automatically.'],
                    ['shield', 'Safe edits & cancellations', 'Edit or cancel a sale with a reason; stock and dues correct themselves.'],
                ],
                'mockup' => ['component' => 'pos', 'frame' => 'browser', 'url' => 'hisabokitab.com/sales', 'label' => 'Sale screen with cart, batch costs, FIFO profit and payment options'],
                'related' => ['stock', 'udhaar', 'reports'],
            ],
            'stock' => [
                'name' => 'Stock & FIFO Costing',
                'summary' => 'Record every purchase by batch and vendor, so stock and payables are always right.',
                'icon' => 'box',
                'anchor' => 'stock',
                'eyebrow' => 'Stock & costing',
                'title' => 'Stock you can count on, profit you can believe',
                'lead' => 'Every purchase is a batch with its own cost. Sales draw from the oldest batch first, so margins reflect what you really paid.',
                'description' => 'HisabOKitab stock and FIFO costing: record Stock In by batch and vendor, cost every unit sold from the batch it came from, and know what you owe each supplier.',
                'items' => [
                    ['box', 'Stock In by batch', 'Record purchases per vendor with quantities, costs and selling prices.'],
                    ['calculator', 'FIFO batch costing', 'Each unit sold is costed from the batch it came from — automatically.'],
                    ['chart', 'Real profit with FIFO', 'Each unit sells at the cost of the batch it came from — no guessing your margins.'],
                    ['users', 'Vendors & party ledger', 'Know what you owe each supplier and what each party owes you.'],
                ],
                'mockup' => ['component' => 'dashboard', 'frame' => 'browser', 'url' => 'hisabokitab.com/dashboard', 'label' => 'Dashboard with revenue chart, items sold and outstanding dues'],
                'spotlights' => [
                    ['FIFO batch costing on every sale', 'Bought the same item at three different prices? Each sale is costed at the true price of the batch it came from, oldest stock first, or closest to expiry first if you track expiry dates, so the profit you see is the profit you made.'],
                ],
                'related' => ['pos', 'reports', 'udhaar'],
            ],
            'udhaar' => [
                'name' => 'Udhaar & Customers',
                'summary' => 'Every rupee a customer owes, itemised by where it came from, with WhatsApp reminders.',
                'icon' => 'wallet',
                'anchor' => 'udhaar',
                'eyebrow' => 'Customers & udhaar',
                'title' => 'Udhaar without the register',
                'lead' => 'Cash udhaar, unpaid sales and service balances add up to one total per customer — every line traceable to where it came from.',
                'description' => 'HisabOKitab udhaar ledger: one clear balance per customer, itemised by the sale or service it came from, with WhatsApp payment reminders and full customer history.',
                'items' => [
                    ['wallet', 'One clear balance', 'Udhaar given and repaid, alongside unpaid sales and services.'],
                    ['receipt', 'Itemised by source', 'See exactly which sale or service each rupee belongs to.'],
                    ['chat', 'WhatsApp reminders', 'Send a polite reminder that lists every due item — no payment links, no surprises.'],
                    ['users', 'Customer history', 'Every purchase, repair and payment for a customer on one page.'],
                    ['store', 'Customers & suppliers', 'Every customer’s purchases and dues, and what you owe each supplier, on one page.'],
                    ['layers', 'Vendors & party ledger', 'Know what you owe each supplier and what each party owes you.'],
                ],
                'mockup' => ['component' => 'udhaar', 'frame' => 'browser', 'url' => 'hisabokitab.com/udhaar', 'label' => 'Customer ledger with each due itemised by source'],
                'spotlights' => [
                    ['Udhaar that explains itself', 'Cash udhaar and unpaid sales roll into one total — itemised, so there’s never an argument about what’s owed.'],
                ],
                'related' => ['pos', 'whatsapp-ordering', 'reports'],
            ],
            'reports' => [
                'name' => 'Reports & Expenses',
                'summary' => 'Revenue, dues, expenses and stock — by day, month or year.',
                'icon' => 'chart',
                'anchor' => 'reports',
                'eyebrow' => 'Numbers you can trust',
                'title' => 'Know your real profit, and exactly who owes you what',
                'lead' => 'Most shops guess their margins and keep udhaar in a register. HisabOKitab does the arithmetic for you — and shows its working.',
                'description' => 'HisabOKitab reports and expenses: a dashboard of sales, services, expenses and dues by day, month or year, with rent, salaries and daily expenses recorded alongside sales.',
                'items' => [
                    ['chart', 'Dashboard & reports', 'Sales, services, expenses and dues by day, month or year.'],
                    ['calculator', 'Expenses & accounts', 'Rent, salaries and daily expenses recorded alongside sales, so your profit is the real one.'],
                ],
                'mockup' => ['component' => 'dashboard', 'frame' => 'browser', 'url' => 'hisabokitab.com/dashboard', 'label' => 'Dashboard with revenue chart, items sold and outstanding dues'],
                'spotlights' => [
                    ['FIFO batch costing on every sale', 'Bought the same item at three different prices? Each sale is costed at the true price of the batch it came from, oldest stock first, or closest to expiry first if you track expiry dates, so the profit you see is the profit you made.'],
                    ['Udhaar that explains itself', 'Cash udhaar and unpaid sales roll into one total — itemised, so there’s never an argument about what’s owed.'],
                ],
                'related' => ['multi-branch', 'stock', 'udhaar'],
            ],
            'multi-branch' => [
                'name' => 'Multi-Branch',
                'summary' => 'Run several shops, a warehouse or offices from one account — shared catalog and stock, each location’s own records.',
                'icon' => 'layers',
                'anchor' => 'branches',
                'eyebrow' => 'Multi-branch',
                'title' => 'One business, every location, one account',
                'lead' => 'Open a second shop, a warehouse for your online store or an office in another city without starting over. Products, customers, suppliers and stock stay shared; each branch records its own work, and you see every branch side by side.',
                'description' => 'HisabOKitab multi-branch: run several shops, a warehouse or offices from one account, with a shared catalog, customers and stock, per-branch sales, stock-ins, udhaar and expenses, staff tied to their branch and an owner’s side-by-side branch report.',
                'items' => [
                    ['store', 'Every location in one account', 'Add branches as you grow — another shop, a warehouse or a regional office — under one login and one plan.'],
                    ['box', 'One catalog, one stock pool', 'Products, prices and stock are shared by every branch, so nothing is set up twice.'],
                    ['receipt', 'Each branch keeps its own records', 'Sales, stock-ins, udhaar, expenses, repairs and other transactions are recorded against the branch they happened at.'],
                    ['users', 'Staff tied to their branch', 'Give a cashier one branch and they see and record only that branch’s work. You, and staff you don’t tie to a branch, see them all.'],
                    ['layers', 'Switch branch in one click', 'Pick the branch you’re working in, or choose All branches to see the whole business at once.'],
                    ['chart', 'Branches side by side', 'Your owner’s report puts every branch’s sales, expenses, net, repairs and commission next to each other, with the total.'],
                    ['wallet', 'One balance per customer', 'A customer who buys at two branches still owes one clear amount — and every branch sees the same figure.'],
                    ['shield', 'Grows with your plan', 'Each plan includes a set number of branches. Need another? Add a branch any time with a monthly or yearly add-on.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['From one counter to many locations', 'A second shop across town, a warehouse behind your online store, or a company with offices in several cities — every location records its own work, while the catalog, customers and stock stay in one place.'],
                ],
                'related' => ['reports', 'stock', 'udhaar'],
            ],
            'whatsapp-ordering' => [
                'name' => 'WhatsApp Ordering',
                'summary' => 'Browse, add to cart and confirm — accepted orders become real sales with stock deducted.',
                'icon' => 'chat',
                'anchor' => 'whatsapp',
                'eyebrow' => 'WhatsApp commerce',
                'title' => 'Your shop, open on WhatsApp around the clock',
                'lead' => 'Connect your WhatsApp Business number and keep using the WhatsApp Business app on your phone at the same time. HisabOKitab handles the ordering, updates and reminders.',
                'description' => 'HisabOKitab WhatsApp ordering: a chatbot that lets customers browse, build a cart and order on WhatsApp, with order updates, payment reminders, restock alerts and review requests in Urdu or English.',
                'items' => [
                    ['chat', 'Chatbot ordering', 'Customers browse your listed products, build a cart and confirm — right inside WhatsApp.'],
                    ['check', 'Accept or reject', 'Accepted orders become real sales: stock deducted, invoice created, customer linked.'],
                    ['bell', 'Order status messages', 'Packed, ready for pickup and out for delivery updates sent automatically.'],
                    ['wallet', 'Payment reminders', 'Reminders that list each due item and its source, with a cooldown so no one is spammed.'],
                    ['heart', 'Restock “notify me”', 'Out of stock? Customers tap to be told the moment it’s back.'],
                    ['star', 'Review requests', 'A review request after delivery, and — with permission — a ready-made post from it.'],
                    ['language', 'Urdu & English', 'The bot answers in the language the customer writes in, including Roman Urdu.'],
                    ['qr', 'QR codes & share kit', 'Printable QR posters that open your chat or catalogue, and a share-to-WhatsApp kit.'],
                ],
                'mockup' => ['component' => 'chat', 'frame' => 'phone', 'label' => 'WhatsApp chat where a customer orders from the shop\'s chatbot'],
                'spotlights' => [
                    ['Sell on WhatsApp while you serve the counter', 'Your own WhatsApp chatbot shows customers what’s in stock, takes the order, and drops it into HisabOKitab for you to accept — in Urdu or English, whichever they write in.'],
                ],
                'related' => ['social-posting', 'meta-ads', 'udhaar'],
            ],
            'social-posting' => [
                'name' => 'Facebook & Instagram Posting',
                'summary' => 'Turn a product or review into a designed post for your Page and Instagram feed.',
                'icon' => 'photo',
                'anchor' => 'marketing',
                'eyebrow' => 'Social & ads',
                'title' => 'Post, promote and advertise — without leaving your shop software',
                'lead' => 'Turn any product into a ready-made post for your Facebook Page and Instagram, feed or Stories. Then boost it with a Meta ad that brings customers straight into your WhatsApp.',
                'description' => 'HisabOKitab Facebook and Instagram posting: turn products and reviews into designed posts and Stories, schedule them ahead, and see posts and reach on one marketing overview.',
                'items' => [
                    ['photo', 'Facebook & Instagram posts', 'Turn a product or review into a designed post for your Page and Instagram feed.'],
                    ['sparkles', 'Stories', 'Publish to Facebook and Instagram Stories in the right format, automatically.'],
                    ['clock', 'Scheduling', 'Plan posts ahead and let HisabOKitab publish them on time.'],
                    ['eye', 'Marketing overview', 'One page for posts, reach and ad performance across channels.'],
                ],
                'mockup' => null,
                'related' => ['meta-ads', 'whatsapp-ordering', 'pos'],
            ],
            'meta-ads' => [
                'name' => 'Meta Ads',
                'summary' => 'Create ads that open a WhatsApp chat, or boost a Facebook post — budget, dates and audience in a few taps.',
                'icon' => 'megaphone',
                'anchor' => 'ads',
                'eyebrow' => 'Social & ads',
                'title' => 'Your marketing team, built into the shop software',
                'lead' => 'Post to Facebook and Instagram, and run Meta ads that land customers in your WhatsApp — paid from your own ad account, with a spend cap you approve.',
                'description' => 'HisabOKitab Meta ads: create ads that open a WhatsApp chat or boost a Facebook post, show them in WhatsApp Status too, approve the maximum spend and trace chats, orders and sales from each ad.',
                'items' => [
                    ['megaphone', 'Meta ads from your dashboard', 'Create ads that open a WhatsApp chat, or boost a Facebook post — budget, dates and audience in a few taps.'],
                    ['chat', 'WhatsApp Status ads', 'Show your ad in people’s WhatsApp Status as well as on Facebook and Instagram.'],
                    ['shield', 'Spend you control', 'Everything is set up paused; you confirm the maximum spend before launch. Pause or stop anytime.'],
                    ['chart', 'Results you can trace', 'Spend, chats, orders and sales from each ad, so you know what your ads really returned.'],
                ],
                'mockup' => ['component' => 'ads', 'frame' => 'browser', 'url' => 'hisabokitab.com/marketing/ads', 'label' => 'Meta ad campaign with placements on Facebook, Instagram and WhatsApp Status, and results'],
                'roadmap' => ['Google Ads', 'YouTube Ads', 'LinkedIn Ads'],
                'related' => ['social-posting', 'whatsapp-ordering', 'reports'],
            ],
            'mobile-shop-services' => [
                'name' => 'Mobile Shop Services',
                'summary' => 'Repairs, SIM sales, balance & wallet loads, bills and NADRA — switched on when you need them.',
                'icon' => 'device',
                'anchor' => 'services',
                'eyebrow' => 'Services & extras',
                'title' => 'Every income stream, not just product sales',
                'lead' => 'Repairs, SIM sales, balance & wallet loads, bills and NADRA — switched on when you need them.',
                'description' => 'HisabOKitab for mobile shops: repairs, SIM sales, balance and wallet loads, utility bills, NADRA verification and your own services, each with its own screen, fees and reporting.',
                'items' => [
                    ['wrench', 'Repairs', 'Intake, status, charges and a “device ready” WhatsApp message.'],
                    ['device', 'SIM sales', 'Sell and track SIMs by network with revenue reporting.'],
                    ['bolt', 'Balance loads', 'Mobile load sales with your fee recorded as revenue.'],
                    ['wallet', 'Wallet loads', 'Cash-in and cash-out with fees tracked in both directions.'],
                    ['receipt', 'Bills', 'Utility bill collection by provider, with fee income.'],
                    ['id', 'NADRA verification', 'Record verifications and the revenue they bring in.'],
                    ['sparkles', 'Services', 'Any other service you offer, with its own categories and pricing.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['Mobile & electronics shops', 'Phones with IMEIs, accessories, SIMs, loads, bills, NADRA and repairs — each with its own screen and fee income.'],
                ],
                'related' => ['pos', 'udhaar', 'reports'],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
