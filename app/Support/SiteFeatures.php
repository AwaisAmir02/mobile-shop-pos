<?php

namespace App\Support;

/**
 * The marketing site's feature pages: the one list behind the header
 * dropdown, the mobile menu, the /features overview, the sitemap and the
 * /features/{slug} routes.
 *
 * Copy here must only describe what shops can use today. Not built, so never
 * listed as a feature: per-branch stock or transfers, CRM, ERP or HR modules
 * (those appear only in ROADMAP, marked coming soon). A page with a 'notice'
 * is built but not on for everyone: the ledger is off until switched on per
 * shop, the online store connection hasn't served a live store yet, and
 * courier booking comes with selected plans only.
 *
 * These are capability pages ("what it does"). Business-type pages ("who it's
 * for") live in SiteIndustries, behind their own header menu; the mobile shop
 * page is in both lists.
 */
class SiteFeatures
{
    /**
     * Pages with copy written after the original single Features page, so
     * SiteFeaturePagesTest doesn't hold them to that page's text.
     */
    public const NEW_COPY = ['pos', 'returns-exchange', 'stock', 'udhaar', 'reports', 'multi-branch', 'purchase-orders', 'payroll', 'cash-balances', 'mobile-shop-services', 'double-entry-ledger', 'online-store', 'courier-booking'];

    /** Planned modules. Not built: shown only with a Coming soon label, never as a feature. */
    public const ROADMAP = [
        ['CRM', 'Customer relationship management: follow-ups, leads and customer notes in one place.'],
        ['ERP', 'Wider business planning across purchasing, stock, production and finance.'],
        ['HR management', 'Staff records and attendance beyond today’s payroll.'],
    ];

    /**
     * @return array<string, array{
     *     name: string, seo_title: string, summary: string, icon: string, anchor: string,
     *     eyebrow: string, title: string, lead: string, description: string,
     *     items: array<int, array{0: string, 1: string, 2: string}>,
     *     mockup: ?array{component: string, frame: string, url?: string, label: string},
     *     spotlights?: array<int, array{0: string, 1: string}>,
     *     badge?: string, notice?: string, roadmap?: array<int, string>, roadmap_title?: string,
     *     related: array<int, string>
     * }>
     */
    public static function all(): array
    {
        return [
            'pos' => [
                'name' => 'Sales & Invoices',
                'seo_title' => 'POS Billing Software in Pakistan: Sales & Invoices',
                'summary' => 'Fast checkout with barcode scanning, partial payments and branded PDF invoices with a QR code.',
                'icon' => 'cart',
                'anchor' => 'counter',
                'eyebrow' => 'Counter & sales',
                'title' => 'A checkout that keeps up with a busy counter',
                'lead' => 'Scan, sell, print — and every sale already knows its cost, its customer and what is still owed.',
                'description' => 'POS billing software for Pakistani shops: barcode checkout, branded PDF invoices with a QR code, cash, JazzCash, Easypaisa or udhaar payments, returns and automatic promotions.',
                'items' => [
                    ['cart', 'Barcode-first checkout', 'Scan or search products, adjust price and discount per line or for the whole invoice.'],
                    ['receipt', 'Branded invoices', 'PDF invoices with your shop’s name, logo and a QR code, ready to print or send on WhatsApp.'],
                    ['wallet', 'Paid, partial or udhaar', 'Take full or part payment in cash or into JazzCash, Easypaisa or bank, and link the balance to the customer automatically.'],
                    ['shield', 'Safe edits & cancellations', 'Edit or cancel a sale with a reason; stock and dues correct themselves.'],
                    ['box', 'Sizes, packs and weights', 'Sell in several sizes or colours, by the box, pack or piece, or by kg, litre, metre or gaz with quantities like 1.5.'],
                    ['sparkles', 'Promotions applied for you', 'Running a sale? Your active promotions take their discount off at the counter automatically.'],
                    ['layers', 'Returns & exchanges', 'Take an item back against its invoice, refund it or give store credit, or swap it in one step.'],
                    ['qr', 'Barcode labels', 'Generate barcodes for products that don’t have one and print the labels yourself.'],
                ],
                'mockup' => ['component' => 'pos', 'frame' => 'browser', 'url' => 'hisabokitab.com/sales', 'label' => 'Sale screen with cart, batch costs, FIFO profit and payment options'],
                'related' => ['returns-exchange', 'stock', 'udhaar'],
            ],
            'returns-exchange' => [
                'name' => 'Returns, Exchange & Promotions',
                'seo_title' => 'Sales Returns, Exchange & Store Credit for Shops',
                'summary' => 'Returns with credit notes, store credit, one-step exchanges, scheduled promotions and box-strip-piece selling — free on every plan.',
                'icon' => 'layers',
                'anchor' => 'returns',
                'eyebrow' => 'Returns & offers',
                'title' => 'Returns, exchanges and sales, done properly',
                'lead' => 'Take items back against the original invoice, give a refund or store credit, swap sizes in one step and run your season sale — with stock, dues and profit correcting themselves.',
                'description' => 'Sales return and exchange software for Pakistani shops: returns with credit note PDFs, store credit, one-step exchanges, scheduled % or flat promotions and box, strip or piece selling.',
                'items' => [
                    ['receipt', 'Returns against the invoice', 'Return all or part of a sale. Items go back to the batch they came from, and the customer’s dues correct themselves.'],
                    ['check', 'Credit note PDFs', 'Every return gets its own credit note, ready to print or hand to the customer.'],
                    ['wallet', 'Refund or store credit', 'Refund in cash, or turn the amount into store credit. One switch makes your shop exchange-and-credit only.'],
                    ['users', 'Store credit per customer', 'Kept apart from udhaar. Spend it at the counter, set an optional expiry, or pay it out.'],
                    ['layers', 'One-step exchange', 'Take the old item back and sell the new one together; the customer pays or gets back only the difference.'],
                    ['clock', 'Your return window', '30 days unless you change it. Only the owner can take back a late return, and the reason is recorded.'],
                    ['sparkles', 'Scheduled promotions', 'Percent or flat off, with start and end dates, on chosen products, groups or categories — applied at the counter automatically.'],
                    ['box', 'Sell by box, strip or piece', 'Set up to three pack levels with their own prices; stock is always counted in single pieces.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['Net sales you can trust', 'Reports show gross sales, returns and net sales side by side. A return counts on the day it happens, and damaged stock can be written off instead of going back on the shelf.'],
                    ['Free on every plan', 'Returns, store credit, exchanges, promotions and pack selling are part of every HisabOKitab plan — for mobile shops, pharmacies, kiryana stores and clothing and shoe shops alike.'],
                ],
                'related' => ['pos', 'udhaar', 'reports'],
            ],
            'stock' => [
                'name' => 'Inventory & FIFO Costing',
                'seo_title' => 'Inventory & Stock Management Software in Pakistan',
                'summary' => 'Stock In by batch and vendor, FIFO costing, expiry dates, variants and write-offs — so stock and profit are always right.',
                'icon' => 'box',
                'anchor' => 'stock',
                'eyebrow' => 'Stock & costing',
                'title' => 'Stock you can count on, profit you can believe',
                'lead' => 'Every purchase is a batch with its own cost. Sales draw from the oldest batch first, or the one closest to expiry, so margins reflect what you really paid.',
                'description' => 'Inventory software for shops in Pakistan: Stock In by batch and vendor, FIFO costing, expiry dates and batch numbers, sizes and colours, cartons, packs and pieces, and write-offs.',
                'items' => [
                    ['box', 'Stock In by batch', 'Record purchases per vendor with quantities, costs and selling prices, plus batch numbers when you track expiry.'],
                    ['calculator', 'FIFO batch costing', 'Each unit sold is costed from the batch it came from — automatically.'],
                    ['clock', 'Expiry date tracking', 'The batch closest to expiry sells first, and expired stock can’t be sold by mistake. On from day one for kiryana stores and pharmacies.'],
                    ['layers', 'Variants & size grid', 'One product in several sizes or colours, each with its own barcode, price and stock — received in one size-by-colour grid.'],
                    ['store', 'Cartons, packs & pieces', 'Buy by the carton or box and sell by the pack or piece; stock is always counted in single pieces.'],
                    ['calculator', 'Weighed & measured items', 'Sell by kg, litre, metre, yard or gaz, down to fractions.'],
                    ['receipt', 'Stock write-offs', 'Take damaged, lost or expired stock off the shelf with a reason, valued at what it cost you.'],
                    ['qr', 'Labels after Stock In', 'Print barcode labels for everything a delivery brought in, straight after you save it.'],
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
                'seo_title' => 'Udhaar App & Digital Khata for Shops in Pakistan',
                'summary' => 'Every rupee a customer owes, itemised by where it came from, with WhatsApp reminders.',
                'icon' => 'wallet',
                'anchor' => 'udhaar',
                'eyebrow' => 'Customers & udhaar',
                'title' => 'Udhaar without the register',
                'lead' => 'Cash udhaar, unpaid sales and service balances add up to one total per customer — every line traceable to where it came from.',
                'description' => 'Udhaar app and digital khata for Pakistani shops: one clear balance per customer, itemised by sale, WhatsApp reminders, a private ledger link and JazzCash payments.',
                'items' => [
                    ['wallet', 'One clear balance', 'Udhaar given and repaid, alongside unpaid sales and services.'],
                    ['receipt', 'Itemised by source', 'See exactly which sale or service each rupee belongs to.'],
                    ['chat', 'WhatsApp reminders', 'Send a polite reminder that lists every due item and links to the customer’s own account page.'],
                    ['eye', 'Their own ledger link', 'Each customer gets a private link to see their balance and every due, any time, without calling you.'],
                    ['phone', 'Pay by JazzCash', 'Customers can pay from their ledger link into your own JazzCash merchant account, and the payment is recorded against their balance.'],
                    ['users', 'Customer history', 'Every purchase, repair and payment for a customer on one page.'],
                    ['layers', 'Vendors & party ledger', 'Know what you owe each supplier and what each party owes you.'],
                    ['store', 'Statements for any dates', 'A running-balance statement for any customer or vendor, ready to settle up or send them a copy.'],
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
                    ['chart', 'Dashboard & reports', 'Sales, returns and net sales, services, expenses and dues by day, month or year.'],
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
            'double-entry-ledger' => [
                'name' => 'Double-Entry Ledger',
                'seo_title' => 'Double-Entry Accounting Ledger for Small Businesses',
                'summary' => 'A double-entry journal built from the records you already keep: trial balance, profit & loss, balance sheet and cash book. Off until switched on for your business.',
                'badge' => 'On request',
                'notice' => 'The ledger is off by default and isn’t switched on for any business automatically. We turn it on for yours when you ask, and you complete a short setup with your opening balances. Your everyday screens work the same with it on or off.',
                'icon' => 'calculator',
                'anchor' => 'ledger',
                'eyebrow' => 'Accounting',
                'title' => 'Proper books, kept from what you already record',
                'lead' => 'Every sale, purchase, payment and expense you enter becomes a balanced double-entry journal entry — so you get a trial balance, profit & loss and balance sheet without keying anything in twice.',
                'description' => 'Double-entry accounting from your daily records: automatic journal entries, trial balance, profit & loss, balance sheet and cash book. Off by default, switched on per business on request.',
                'items' => [
                    ['layers', 'Posts itself', 'Sales, stock-ins, payments, udhaar, expenses, counter services and payslips each become a balanced journal entry automatically.'],
                    ['calculator', 'Trial balance', 'Every account and its balance, with debits always equal to credits.'],
                    ['chart', 'Profit & loss', 'Income and expenses from the journal, for any period you pick.'],
                    ['receipt', 'Balance sheet', 'What the business owns and owes on any date.'],
                    ['wallet', 'Cash book', 'Cash and bank movements, day by day.'],
                    ['store', 'Branch profit', 'Profit for each branch from the same journal.'],
                    ['users', 'Owner & held money', 'Money the owner put in or took out, and salary held back on payslips.'],
                    ['shield', 'Ledger health', 'Checks the books against your stock, customer dues and supplier balances, and flags any difference.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['Starts from the day you set it up', 'The ledger opens with the balances you enter during setup and posts from then on. It doesn’t rewrite your past records, and switching it off freezes it read-only.'],
                ],
                'related' => ['reports', 'cash-balances', 'multi-branch'],
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
            'online-store' => [
                'name' => 'Online Store Connection',
                'seo_title' => 'Connect Your Online Store: Live Stock & Orders API',
                'summary' => 'Connect your own website: publish products and live stock from HisabOKitab, and take its orders into the same inbox. New — set up with your developer.',
                'badge' => 'New',
                'notice' => 'New: the connection is built and tested, but no store is using it live yet. It needs your store’s developer to connect to our API, so talk to us and we’ll set up the first connection with you.',
                'icon' => 'store',
                'anchor' => 'online-store',
                'eyebrow' => 'Online store',
                'title' => 'Your website and your counter, selling from one stock',
                'lead' => 'Your own online store reads products and live stock from HisabOKitab and sends its orders back in. Staff accept each order, and an accepted order becomes a real sale — the counter and the website never sell stock twice by mistake.',
                'description' => 'Connect your own online store to HisabOKitab: publish products and live stock, receive orders for staff to accept, and send order statuses back, over a secure REST API and signed webhooks.',
                'items' => [
                    ['box', 'Publish chosen products', 'Pick which products your store shows, with their images. Sending your counter prices is optional.'],
                    ['chart', 'Live stock, one source', 'HisabOKitab is the only place stock changes. Your store gets an update when it does, plus a full refresh every night.'],
                    ['clock', 'Pending orders held back', 'Stock in orders waiting for a decision is taken off what the store shows, with an optional safety buffer.'],
                    ['check', 'Accept or reject', 'Each online order lands in HisabOKitab. Accepted orders become sales, with stock and cost from the right batch.'],
                    ['bell', 'Status back to the store', 'Accepted, rejected, fulfilment updates and cancellations are sent back as signed events.'],
                    ['shield', 'Locked to your business', 'Each connection has its own scoped API token and signing secret, and can reach only your business’s data.'],
                    ['layers', 'Pause any time', 'Pausing stops stock updates but still lets customer cancellations through; resuming sends a full stock refresh.'],
                    ['receipt', 'Documented API', 'A versioned REST API with signed webhooks, documented for your store’s developer.'],
                ],
                'mockup' => null,
                'spotlights' => [
                    ['For stores with a developer', 'This is an API connection, not a one-click plugin: your website needs a developer to connect it. Ready-made plugins for specific store platforms aren’t available.'],
                ],
                'related' => ['stock', 'pos', 'whatsapp-ordering'],
            ],
            'courier-booking' => [
                'name' => 'Courier Booking',
                'seo_title' => 'Courier Booking for Online Sellers: Leopards & PostEx',
                'summary' => 'Book Leopards and PostEx parcels straight from a sale with your own courier account — labels, COD, live status and returns.',
                'badge' => 'New',
                'notice' => 'Courier booking comes with selected plans — see Pricing for which. You connect your own Leopards or PostEx merchant account; HisabOKitab never holds your parcels’ cash.',
                'icon' => 'map',
                'anchor' => 'couriers',
                'eyebrow' => 'Couriers & delivery',
                'title' => 'Book the parcel from the sale, not from another website',
                'lead' => 'Connect your own Leopards or PostEx account once. Then every sale — at the counter, on WhatsApp or from your online store — can be booked, labelled and tracked without copying an address twice.',
                'description' => 'Courier booking software for Pakistani online sellers: book Leopards and PostEx parcels from a sale with your own account, print labels, collect COD and track every parcel.',
                'items' => [
                    ['lock', 'Your own courier account', 'Connect your own Leopards or PostEx merchant account. Rates and cash on delivery stay between you and your courier.'],
                    ['cart', 'Book from the sale', 'Open a sale and book it: name, address, a city from the courier’s own list, weight and pieces.'],
                    ['wallet', 'Cash on delivery', 'What’s still owed on the sale is filled in as the COD amount, or 0 if it’s already paid.'],
                    ['receipt', 'Labels to print', 'Print each parcel’s courier label straight from HisabOKitab.'],
                    ['map', 'Live parcel status', 'Every parcel’s status on one Shipments screen, checked with the courier every 30 minutes or when you refresh.'],
                    ['box', 'Returned parcels', 'When a parcel comes back, mark it received back to put the stock back, and cancel the sale if you need to.'],
                    ['close', 'Cancel a booking', 'Booked by mistake? Cancel the parcel with the courier from the same screen.'],
                    ['chat', 'Any sale, any channel', 'Counter sales, accepted WhatsApp orders and online store orders can all be booked the same way.'],
                ],
                'mockup' => null,
                'roadmap_title' => 'More couriers on the roadmap',
                'roadmap' => ['TCS', 'Trax', 'M&P'],
                'spotlights' => [
                    ['Built for Pakistani online sellers', 'Selling on Facebook, Instagram, WhatsApp or your own website? Keep the order, the stock, the parcel and the cash collected in one place, instead of a courier portal and a notebook.'],
                ],
                'related' => ['whatsapp-ordering', 'online-store', 'pos'],
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
                'seo_title' => 'Mobile Shop Software Pakistan: IMEI, Repairs & Loads',
                'summary' => 'IMEI checks, warranty, instalments, buybacks, repairs, SIMs, loads, bills and NADRA — each with its own screen and income.',
                'icon' => 'device',
                'anchor' => 'services',
                'eyebrow' => 'Mobile & electronics shops',
                'title' => 'Every income stream, not just product sales',
                'lead' => 'Phones with checked IMEIs, warranty and instalments, used-phone buybacks, repairs, SIM sales, balance & wallet loads, bills and NADRA — switched on for mobile shops.',
                'description' => 'Mobile shop software for Pakistan: IMEI checks, warranty, instalment sales, used-phone buyback and trade-in, repairs, SIMs, balance and wallet loads, bills and NADRA.',
                'items' => [
                    ['shield', 'IMEI check', 'Every IMEI must be 15 digits and pass the standard check digit, and the same IMEI can’t be entered twice.'],
                    ['check', 'Warranty check', 'Brand or shop warranty is saved with the serial on each sale, so you can look up whether a phone is still covered.'],
                    ['calculator', 'Instalment sales', 'Split a sale into dated instalments; see what’s paid, due and late, and send WhatsApp reminders.'],
                    ['phone', 'Used-phone buyback', 'Buy a used phone over the counter with its IMEI and condition, and it goes into stock ready to resell.'],
                    ['layers', 'Trade-in at the counter', 'Take the customer’s old phone as part payment on a new one, in the same sale.'],
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
