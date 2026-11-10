<?php

namespace App\Support;

/**
 * The marketing site's feature pages: the one list behind the header
 * dropdown, the mobile menu, the /features overview, the sitemap and the
 * /features/{slug} routes.
 *
 * Copy here must only describe what shops can use today. Not built, so never
 * listed: per-branch stock or transfers, CRM, ERP or HR modules. Built but
 * held back from the site until the owner says otherwise: the double-entry
 * ledger and the online store connector.
 */
class SiteFeatures
{
    /**
     * Pages with copy written after the original single Features page, so
     * SiteFeaturePagesTest doesn't hold them to that page's text.
     */
    public const NEW_COPY = ['pos', 'stock', 'udhaar', 'reports', 'multi-branch', 'purchase-orders', 'payroll', 'cash-balances', 'mobile-shop-services'];

    /**
     * @return array<string, array{
     *     name: string, seo_title: string, summary: string, icon: string, anchor: string,
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
                'seo_title' => 'POS Billing & Invoicing Software',
                'summary' => 'Fast checkout with barcode scanning, partial payments and branded PDF invoices with a QR code.',
                'icon' => 'cart',
                'anchor' => 'counter',
                'eyebrow' => 'Counter & sales',
                'title' => 'A checkout that keeps up with a busy counter',
                'lead' => 'Scan, sell, print — and every sale already knows its cost, its customer and what is still owed.',
                'description' => 'POS billing software for Pakistani businesses: barcode checkout, branded PDF invoices with a QR code, cash, account, partial or udhaar payments, and safe edits.',
                'items' => [
                    ['cart', 'Barcode-first checkout', 'Scan or search products, adjust price and discount per line or for the whole invoice.'],
                    ['receipt', 'Branded invoices', 'PDF invoices with your shop’s name, logo and a QR code, ready to print or send on WhatsApp.'],
                    ['wallet', 'Paid, partial or udhaar', 'Take full or part payment in cash or into JazzCash, Easypaisa or bank, and link the balance to the customer automatically.'],
                    ['shield', 'Safe edits & cancellations', 'Edit or cancel a sale with a reason; stock and dues correct themselves.'],
                    ['box', 'Sizes, colours and weights', 'Sell one product in several sizes or colours, or by kg, litre, metre or gaz with quantities like 1.5.'],
                    ['qr', 'Barcode labels', 'Generate barcodes for products that don’t have one and print the labels yourself.'],
                ],
                'mockup' => ['component' => 'pos', 'frame' => 'browser', 'url' => 'hisabokitab.com/sales', 'label' => 'Sale screen with cart, batch costs, FIFO profit and payment options'],
                'related' => ['stock', 'udhaar', 'cash-balances'],
            ],
            'stock' => [
                'name' => 'Inventory & FIFO Costing',
                'seo_title' => 'Inventory & Stock Management Software',
                'summary' => 'Stock In by batch and vendor, FIFO costing, expiry dates, variants and write-offs — so stock and profit are always right.',
                'icon' => 'box',
                'anchor' => 'stock',
                'eyebrow' => 'Stock & costing',
                'title' => 'Stock you can count on, profit you can believe',
                'lead' => 'Every purchase is a batch with its own cost. Sales draw from the oldest batch first, or the one closest to expiry, so margins reflect what you really paid.',
                'description' => 'Inventory software for shops and businesses in Pakistan: Stock In by batch and vendor, FIFO costing, expiry tracking, sizes and colours, weighed items and stock write-offs.',
                'items' => [
                    ['box', 'Stock In by batch', 'Record purchases per vendor with quantities, costs and selling prices.'],
                    ['calculator', 'FIFO batch costing', 'Each unit sold is costed from the batch it came from — automatically.'],
                    ['clock', 'Expiry date tracking', 'For pharmacies and grocers: the batch closest to expiry sells first, and expired stock can’t be sold by mistake.'],
                    ['layers', 'Variants', 'One product in several sizes or colours, each with its own barcode, price and stock count.'],
                    ['calculator', 'Weighed & measured items', 'Sell by kg, litre, metre, yard or gaz, down to fractions.'],
                    ['receipt', 'Stock write-offs', 'Take damaged, lost or expired stock off the shelf with a reason, valued at what it cost you.'],
                    ['chart', 'Excel product import', 'Bring a whole catalogue in from an Excel or CSV sheet instead of typing it.'],
                    ['users', 'Vendors & party ledger', 'Know what you owe each supplier and what each party owes you.'],
                ],
                'mockup' => ['component' => 'dashboard', 'frame' => 'browser', 'url' => 'hisabokitab.com/dashboard', 'label' => 'Dashboard with revenue chart, items sold and outstanding dues'],
                'spotlights' => [
                    ['FIFO batch costing on every sale', 'Bought the same item at three different prices? Each sale is costed at the true price of the batch it came from, oldest stock first, or closest to expiry first if you track expiry dates, so the profit you see is the profit you made.'],
                ],
                'related' => ['purchase-orders', 'pos', 'reports'],
            ],
            'purchase-orders' => [
                'name' => 'Purchase Orders & Vendor Invoices',
                'seo_title' => 'Purchase Order & Goods Receipt Software',
                'summary' => 'Order from vendors, receive goods against the order and check every vendor bill against what you ordered and received.',
                'icon' => 'receipt',
                'anchor' => 'purchasing',
                'eyebrow' => 'Purchasing',
                'title' => 'Buy from suppliers with a paper trail you can check',
                'lead' => 'Raise a purchase order, receive the goods against it — including part deliveries — and match the vendor’s bill to what you actually ordered and received before anyone pays it.',
                'description' => 'Purchase order software for Pakistani businesses: send POs to vendors, receive goods in part or full, match vendor invoices to orders and receipts, and approve before paying.',
                'items' => [
                    ['receipt', 'Purchase orders', 'Order from a vendor with quantities and agreed prices, and keep track of what is still to arrive.'],
                    ['box', 'Receive goods', 'Receive a delivery against its order, in full or in parts; received stock goes straight into inventory.'],
                    ['calculator', 'Three-way match', 'Each vendor invoice is checked against what you ordered and what you received, and any difference is shown before approval.'],
                    ['shield', 'Approve, dispute & pay', 'Recording a bill and approving or paying it are separate permissions you can give to different people.'],
                    ['users', 'Vendor balances stay right', 'What you owe each vendor follows the goods received, and every payment is on their ledger.'],
                    ['layers', 'Stock In still works', 'Quick purchases can still go through plain Stock In alongside your purchase orders.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['Built for businesses that buy in bulk', 'Distributors, wholesalers and multi-location shops get the order, the delivery and the bill in one chain — so a short delivery or a wrong price is caught before it is paid.'],
                ],
                'related' => ['stock', 'cash-balances', 'udhaar'],
            ],
            'udhaar' => [
                'name' => 'Udhaar & Customers',
                'seo_title' => 'Udhaar & Customer Ledger Software',
                'summary' => 'Every rupee a customer owes, itemised by where it came from, with WhatsApp reminders.',
                'icon' => 'wallet',
                'anchor' => 'udhaar',
                'eyebrow' => 'Customers & udhaar',
                'title' => 'Udhaar without the register',
                'lead' => 'Cash udhaar, unpaid sales and service balances add up to one total per customer — every line traceable to where it came from.',
                'description' => 'Digital udhaar khata and customer ledger: one clear balance per customer, itemised by sale or service, party ledger statements and WhatsApp payment reminders.',
                'items' => [
                    ['wallet', 'One clear balance', 'Udhaar given and repaid, alongside unpaid sales and services.'],
                    ['receipt', 'Itemised by source', 'See exactly which sale or service each rupee belongs to.'],
                    ['chat', 'WhatsApp reminders', 'Send a polite reminder that lists every due item — no payment links, no surprises.'],
                    ['users', 'Customer history', 'Every purchase, repair and payment for a customer on one page.'],
                    ['store', 'Customers & suppliers', 'Every customer’s purchases and dues, and what you owe each supplier, on one page.'],
                    ['layers', 'Vendors & party ledger', 'Know what you owe each supplier and what each party owes you.'],
                    ['eye', 'Statements for any dates', 'A running-balance statement for any customer or vendor, ready to settle up or send them a copy.'],
                ],
                'mockup' => ['component' => 'udhaar', 'frame' => 'browser', 'url' => 'hisabokitab.com/udhaar', 'label' => 'Customer ledger with each due itemised by source'],
                'spotlights' => [
                    ['Udhaar that explains itself', 'Cash udhaar and unpaid sales roll into one total — itemised, so there’s never an argument about what’s owed.'],
                ],
                'related' => ['pos', 'whatsapp-ordering', 'cash-balances'],
            ],
            'cash-balances' => [
                'name' => 'Cash & Balances',
                'seo_title' => 'Cash in Hand, JazzCash, Easypaisa & Bank Balances',
                'summary' => 'Cash in hand and every JazzCash, Easypaisa and bank account — live balances and what came in and went out each day.',
                'icon' => 'calculator',
                'anchor' => 'cash',
                'eyebrow' => 'Cash & accounts',
                'title' => 'Know exactly how much money is where',
                'lead' => 'Cash in the drawer, JazzCash, Easypaisa, the bank: HisabOKitab adds up every payment you already record and shows each balance, plus the day’s money in and out.',
                'description' => 'Track cash in hand and JazzCash, Easypaisa and bank balances in one place: live balances, a daily cash flow, transfers between accounts and end-of-day counts.',
                'items' => [
                    ['wallet', 'Every balance on one screen', 'Cash in hand and each shop account, updated by every sale, payment, load, bill and expense you record.'],
                    ['chart', 'Daily flow', 'What came in and went out each day, for cash and every account, with the entries behind each total.'],
                    ['layers', 'Move money', 'Cash deposited in the bank or an account topped up from another, recorded as a move between your own accounts.'],
                    ['check', 'End-of-day count', 'Enter what you counted; over or short is kept as a correction, so the balance matches the drawer.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['No separate cash book to keep', 'Sales, udhaar repayments, wallet loads, bills, vendor payments, expenses and payroll already say whether they were cash or which account — Cash & Balances simply adds them up.'],
                ],
                'related' => ['reports', 'mobile-shop-services', 'udhaar'],
            ],
            'reports' => [
                'name' => 'Reports & Expenses',
                'seo_title' => 'Business Reports, Profit & Expense Tracking',
                'summary' => 'Gross profit, sales, stock, dues and expenses — by day, month or year, exportable to Excel and PDF.',
                'icon' => 'chart',
                'anchor' => 'reports',
                'eyebrow' => 'Numbers you can trust',
                'title' => 'Know your real profit, and exactly who owes you what',
                'lead' => 'Most shops guess their margins and keep udhaar in a register. HisabOKitab does the arithmetic for you — and shows its working.',
                'description' => 'Business reports for Pakistani shops and companies: gross profit by product, sales, stock, dues and expenses by day, month or year, with Excel and PDF export.',
                'items' => [
                    ['chart', 'Dashboard & reports', 'Sales, services, expenses and dues by day, month or year.'],
                    ['calculator', 'Gross profit', 'Revenue against the cost of goods sold, product by product, from the real batch costs.'],
                    ['receipt', 'Excel & PDF export', 'Filter and total the records of any screen — sales, stock in, loads, bills, repairs — and export them.'],
                    ['wallet', 'Expenses & accounts', 'Rent, salaries and daily expenses recorded alongside sales, so your profit is the real one.'],
                    ['eye', 'Every report in one place', 'One reports page with sales, stock, write-offs, party ledgers, udhaar, expenses and payroll history.'],
                    ['layers', 'Branches side by side', 'Owners compare each branch’s sales, expenses and net with the total.'],
                ],
                'mockup' => ['component' => 'dashboard', 'frame' => 'browser', 'url' => 'hisabokitab.com/dashboard', 'label' => 'Dashboard with revenue chart, items sold and outstanding dues'],
                'spotlights' => [
                    ['FIFO batch costing on every sale', 'Bought the same item at three different prices? Each sale is costed at the true price of the batch it came from, oldest stock first, or closest to expiry first if you track expiry dates, so the profit you see is the profit you made.'],
                    ['Udhaar that explains itself', 'Cash udhaar and unpaid sales roll into one total — itemised, so there’s never an argument about what’s owed.'],
                ],
                'related' => ['multi-branch', 'cash-balances', 'stock'],
            ],
            'payroll' => [
                'name' => 'Payroll',
                'seo_title' => 'Payroll & Salary Software for Small Businesses',
                'summary' => 'Staff salaries, advances and deductions, monthly payslips with PDFs — paid salaries land in your expenses.',
                'icon' => 'users',
                'anchor' => 'payroll',
                'eyebrow' => 'Payroll',
                'title' => 'Pay your staff without the salary register',
                'lead' => 'Keep each employee’s salary, advances and deductions in one place, run payroll once a month and hand out payslips — the salaries paid flow straight into your expenses and profit.',
                'description' => 'Payroll software for Pakistani shops and businesses: monthly or daily-wage staff, salary advances and deductions, monthly payslips with PDF, and salaries posted to expenses.',
                'items' => [
                    ['users', 'Employee records', 'Monthly salary or daily wage, with each person’s details and history.'],
                    ['wallet', 'Advances', 'Record advances given, repaid in cash, deducted from salary or written off.'],
                    ['calculator', 'Deductions', 'Your own deduction types, applied when the month’s payslips are made.'],
                    ['receipt', 'Payslips & PDFs', 'Generate the month’s draft payslips, check them, mark them paid and print each one as a PDF.'],
                    ['chart', 'Salaries in your expenses', 'Paid salaries appear in Expenses and in your profit, without entering them twice.'],
                    ['shield', 'Private by permission', 'Only staff you allow can see salaries, and running payroll is a separate permission.'],
                ],
                'mockup' => null,
                'related' => ['reports', 'cash-balances', 'multi-branch'],
            ],
            'multi-branch' => [
                'name' => 'Multi-Branch',
                'seo_title' => 'Multi-Branch Business Software',
                'summary' => 'Run several shops, a warehouse or offices from one account — shared catalog and stock, each location’s own records.',
                'icon' => 'layers',
                'anchor' => 'branches',
                'eyebrow' => 'Multi-branch',
                'title' => 'One business, every location, one account',
                'lead' => 'Open a second shop, a warehouse for your online store or an office in another city without starting over. Products, customers, suppliers and stock stay shared; each branch records its own work, and you see every branch side by side.',
                'description' => 'Multi-branch software for Pakistani businesses: several shops, a warehouse or offices on one account, shared catalog and stock, per-branch sales and expenses, side-by-side reports.',
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
                'related' => ['reports', 'payroll', 'purchase-orders'],
            ],
            'whatsapp-ordering' => [
                'name' => 'WhatsApp Ordering',
                'seo_title' => 'WhatsApp Ordering Chatbot for Your Business',
                'summary' => 'Browse, add to cart and confirm — accepted orders become real sales with stock deducted.',
                'icon' => 'chat',
                'anchor' => 'whatsapp',
                'eyebrow' => 'WhatsApp commerce',
                'title' => 'Your shop, open on WhatsApp around the clock',
                'lead' => 'Connect your WhatsApp Business number and keep using the WhatsApp Business app on your phone at the same time. HisabOKitab handles the ordering, updates and reminders.',
                'description' => 'WhatsApp ordering chatbot: customers browse, build a cart and order on WhatsApp, with order updates, payment reminders, restock alerts and review requests in Urdu or English.',
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
                'seo_title' => 'Facebook & Instagram Posting from Your Product List',
                'summary' => 'Turn a product or review into a designed post for your Page and Instagram feed.',
                'icon' => 'photo',
                'anchor' => 'marketing',
                'eyebrow' => 'Social & ads',
                'title' => 'Post, promote and advertise — without leaving your shop software',
                'lead' => 'Turn any product into a ready-made post for your Facebook Page and Instagram, feed or Stories. Then boost it with a Meta ad that brings customers straight into your WhatsApp.',
                'description' => 'Facebook and Instagram posting for businesses: turn products and reviews into designed posts and Stories, schedule them ahead, and see posts and reach on one overview.',
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
                'seo_title' => 'Run Facebook, Instagram & WhatsApp Ads',
                'summary' => 'Create ads that open a WhatsApp chat, or boost a Facebook post — budget, dates and audience in a few taps.',
                'icon' => 'megaphone',
                'anchor' => 'ads',
                'eyebrow' => 'Social & ads',
                'title' => 'Your marketing team, built into the shop software',
                'lead' => 'Post to Facebook and Instagram, and run Meta ads that land customers in your WhatsApp — paid from your own ad account, with a spend cap you approve.',
                'description' => 'Run Meta ads from your business software: click-to-WhatsApp ads and boosted posts on Facebook, Instagram and WhatsApp Status, with a spend cap and traceable sales.',
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
                'name' => 'Mobile Shop & Counter Services',
                'seo_title' => 'Mobile Shop Software: Repairs, Loads, SIMs & Bills',
                'summary' => 'Repairs, SIM sales, balance & wallet loads, bills, NADRA and your own services — each with its own screen and fee income.',
                'icon' => 'device',
                'anchor' => 'services',
                'eyebrow' => 'Services & extras',
                'title' => 'Every income stream, not just product sales',
                'lead' => 'Repairs, SIM sales, balance & wallet loads, bills and NADRA — switched on when you need them.',
                'description' => 'Mobile shop software for Pakistan: repairs, SIM sales, balance loads, JazzCash and Easypaisa wallet loads, bill payments, NADRA verification and services, each with fees.',
                'items' => [
                    ['wrench', 'Repairs', 'Intake, status, charges and a “device ready” WhatsApp message.'],
                    ['device', 'SIM sales', 'Sell and track SIMs by network with revenue reporting.'],
                    ['bolt', 'Balance loads', 'Mobile load sales with your fee recorded as revenue.'],
                    ['wallet', 'Wallet loads', 'JazzCash and Easypaisa cash-in and cash-out, with the fee and the account balance tracked both ways.'],
                    ['receipt', 'Bills', 'Utility bill collection by provider, with fee income.'],
                    ['id', 'NADRA verification', 'Record verifications and the revenue they bring in.'],
                    ['sparkles', 'Services', 'Any other service you offer — photocopies, installations, setup — with its own categories and pricing.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['Mobile & electronics shops', 'Phones with IMEIs, accessories, SIMs, loads, bills, NADRA and repairs — each with its own screen and fee income.'],
                ],
                'related' => ['cash-balances', 'pos', 'udhaar'],
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
