<?php

namespace App\Support;

use App\Enums\BusinessCategory;
use App\Enums\BusinessGroup;

/**
 * The marketing site's "who it's for" pages, behind the header's Industries
 * menu, the /industries overview, the home page's "Who it's for" cards, the
 * footer and the sitemap. Two levels, both served at /industries/{slug}:
 *
 *  - groups(): one overview page per BusinessGroup (Shops, Wholesale,
 *    Online, Services), listing its business types;
 *  - all(): one full page per business type (BusinessCategory), with the
 *    same shape as a SiteFeatures page plus `group`, `category` and `short`.
 *
 * Every signup category except "Other shop" has exactly one page, and its
 * group is the category's own BusinessGroup (SiteFeaturePagesTest checks
 * both). Related trades that one shop may run together
 * (BusinessCategory::combinesWith, e.g. salon + spa) link to each other
 * through `pair`.
 *
 * Copy only describes what these businesses can use today. A group's
 * `badge` and `notice` (built, not yet proven on live shops) apply to each
 * of its pages unless the page sets its own. Capability pages ("what it
 * does") stay in SiteFeatures.
 */
class SiteIndustries
{
    /**
     * @return array<string, array{
     *     group: BusinessGroup, name: string, seo_title: string, summary: string, icon: string,
     *     eyebrow: string, title: string, lead: string, description: string,
     *     shared_title: string, items: array<int, array{0: string, 1: string, 2: string}>,
     *     spotlights?: array<int, array{0: string, 1: string}>,
     *     badge?: string, notice?: string, related: array<int, string>
     * }>
     */
    public static function groups(): array
    {
        return [
            'retail-shops' => [
                'group' => BusinessGroup::Retail,
                'name' => 'Shops & Retail',
                'seo_title' => 'Retail POS Software for Shops in Pakistan',
                'summary' => 'Counter billing, stock and udhaar, set up for mobile shops, pharmacies, kiryana stores, clothing and shoe shops — or any other shop.',
                'icon' => 'store',
                'eyebrow' => 'Shops & retail',
                'title' => 'POS software set up for the shop you run',
                'lead' => 'Whatever you sell over the counter, HisabOKitab bills it fast, costs it from the right batch and keeps every customer’s udhaar — then switches on the screens your trade needs.',
                'description' => 'Retail POS software for Pakistani shops: barcode billing, FIFO stock, udhaar with WhatsApp reminders, returns and store credit, set up for mobile shops, pharmacies, kiryana and clothing.',
                'shared_title' => 'What every shop gets',
                'items' => [
                    ['cart', 'Barcode billing', 'Scan or search, then take cash, JazzCash, Easypaisa, part payment or udhaar on the same screen.'],
                    ['box', 'Stock by batch', 'Every purchase is a batch with its own cost, and each sale is costed from the batch it came from.'],
                    ['wallet', 'Udhaar khata', 'One clear balance per customer, itemised by sale, with WhatsApp reminders and their own ledger link.'],
                    ['layers', 'Returns & store credit', 'Take items back against the bill, refund them or give store credit, or exchange them in one step.'],
                    ['sparkles', 'Promotions', 'Percent or flat off on chosen items, with start and end dates, taken off at the counter for you.'],
                    ['receipt', 'Suppliers & purchasing', 'Stock In by vendor, purchase orders and vendor bills, and what you owe each supplier.'],
                    ['calculator', 'Cash & balances', 'Cash in the drawer and every JazzCash, Easypaisa and bank account, with the day’s money in and out.'],
                    ['chart', 'Profit you can trust', 'Gross profit, sales, dues and expenses by day, month or year, exportable to Excel and PDF.'],
                ],
                'spotlights' => [
                    ['Any other shop, too', 'Hardware, cosmetics, stationery, gifts or a general store: sign up as “Other shop” and every core tool is yours — billing, stock, udhaar, returns, promotions and reports.'],
                ],
                'related' => ['pos', 'returns-exchange', 'udhaar'],
            ],
            'wholesale-distribution' => [
                'group' => BusinessGroup::Wholesale,
                'name' => 'Wholesale & Distribution',
                'seo_title' => 'Distribution & Wholesale Software in Pakistan',
                'summary' => 'Trade prices, credit limits, dues aging, cheques and trade schemes for wholesalers, traders and distributors.',
                'icon' => 'building',
                'eyebrow' => 'Wholesale & distribution',
                'title' => 'Sell in bulk on credit, and still know who owes what',
                'lead' => 'A trade price for every business customer, credit limits the counter respects, payment terms with an aging report and cheques tracked until they clear — plus sales orders, load sheets and schemes for distributors.',
                'description' => 'Distribution and wholesale software for Pakistan: trade price lists, credit limits, dues aging, cheque and PDC tracking, trade schemes, load sheets and routes for wholesalers and distributors.',
                'shared_title' => 'What every wholesale business gets',
                'items' => [
                    ['tag', 'Trade & wholesale prices', 'A second, lower price on every product. Business customers get it automatically; walk-ins pay your normal price.'],
                    ['eye', 'Last rate at a glance', 'See the rate you last gave this customer for a product, and use it again with one tap.'],
                    ['shield', 'Credit limits', 'A sale that would take a customer past their limit stops until the owner approves it, with the reason recorded.'],
                    ['clock', 'Payment terms & due dates', 'Give a customer 15 or 30 days, and every credit bill gets its due date from their terms.'],
                    ['chart', 'Dues aging report', 'Who owes what for 0–30, 31–60, 61–90 and 90+ days, with bills and cash udhaar kept apart.'],
                    ['banknotes', 'One payment, many bills', 'Take a lump sum and it settles the customer’s oldest bills first, with a numbered receipt PDF.'],
                    ['id', 'Business customer details', 'Business name, contact person, area, NTN, STRN and CNIC on every trade customer.'],
                    ['receipt', 'Invoices with balances', 'Each invoice prints the customer’s previous balance, this bill, the total owed and the payment due date.'],
                ],
                'spotlights' => [
                    ['Credit control and recovery in one place', 'Recovery is where wholesale money goes missing. Credit limits, due dates, the aging report, cheques that pay nothing until they clear and a running statement for every party keep the counter and the books in agreement.'],
                ],
                'related' => ['purchase-orders', 'udhaar', 'reports'],
            ],
            'online-sellers' => [
                'group' => BusinessGroup::Online,
                'name' => 'Online Sellers',
                'seo_title' => 'Software for Online Sellers in Pakistan: COD & Stock',
                'summary' => 'COD parcels, courier payouts, RTO returns, Daraz imports and Shopify or WooCommerce stock, for social sellers, Daraz sellers and online brands.',
                'icon' => 'globe',
                'badge' => 'New',
                'notice' => 'New: the online-seller tools are built and tested, and rolling out now. Daraz files, Shopify and WooCommerce haven’t been run against a real seller’s account yet, so talk to us and we’ll set up your first one with you.',
                'eyebrow' => 'Online sellers',
                'title' => 'Every parcel, every payout, one stock',
                'lead' => 'Sell on Instagram, Facebook, WhatsApp, Daraz or your own store. HisabOKitab keeps one stock count, knows which courier is holding your cash, and reconciles the payout when it lands.',
                'description' => 'Inventory and COD software for Pakistani online sellers: delivery charges, courier payouts net of fees, RTO returns, Daraz order and payout imports, and Shopify or WooCommerce stock in step.',
                'shared_title' => 'What every online seller gets',
                'items' => [
                    ['chat', 'Every channel on the bill', 'Mark each order as social media, WhatsApp, your website or Daraz, and see which channel really sells.'],
                    ['truck', 'Delivery details & charges', 'Address, city and a delivery charge on the order. The charge counts as income, not a discount.'],
                    ['lock', 'No selling the last piece twice', 'Stock in pending WhatsApp, website and booked orders is held back, so two buyers can’t both get it.'],
                    ['banknotes', 'Courier payouts', 'One payout covering many parcels, net of the courier’s fees and tax. COD the courier holds never shows as customer udhaar.'],
                    ['return', 'RTO & returns', 'Receive a returned parcel, put the stock back, write off damaged pieces and note the courier’s return charge.'],
                    ['bell', 'Refusal warnings', 'A buyer whose phone number refused parcels before is flagged when you take their next order.'],
                    ['map', 'Any courier', 'Book Leopards or PostEx from the sale on plans with courier booking, or type in a parcel from any other courier.'],
                    ['star', 'Review requests', 'Ask for a rating on WhatsApp once a parcel is delivered, with a simple feedback page.'],
                ],
                'spotlights' => [
                    ['Built for cash on delivery', 'Once a parcel is booked, its cash is owed by the courier, not the customer. It stays out of udhaar and reminders until the courier pays you, and the payout shows exactly what each parcel brought in.'],
                ],
                'related' => ['courier-booking', 'whatsapp-ordering', 'online-store'],
            ],
            'service-businesses' => [
                'group' => BusinessGroup::ServiceTrades,
                'name' => 'Services & Appointments',
                'seo_title' => 'Salon, Gym & Workshop Software in Pakistan',
                'summary' => 'Appointments, job cards, memberships, staff commission and attendance for workshops, salons, spas, gyms and trainers.',
                'icon' => 'calendar',
                'badge' => 'New',
                'notice' => 'New: the service tools are built and tested, and rolling out now. Automatic WhatsApp reminders start once Meta approves their messages; until then, one-tap WhatsApp reminders work from every screen.',
                'eyebrow' => 'Services & appointments',
                'title' => 'Sell your time and skill, not just products',
                'lead' => 'Book the chair, the bay or the class, bill services and products on one invoice, and pay every person their commission — with memberships, job cards and attendance built in.',
                'description' => 'Software for Pakistani service businesses: appointments and walk-ins, job cards, memberships and packages, staff commission into payroll, staff attendance and WhatsApp reminders.',
                'shared_title' => 'What every service business gets',
                'items' => [
                    ['sparkles', 'Service menu', 'Every service with its price and time, sold on the same bill as your products.'],
                    ['users', 'Staff commission', 'A % or a fixed amount per service, category or membership, after discounts and returns — straight onto the payslip.'],
                    ['finger', 'Staff attendance', 'Staff clock in with a PIN or a QR badge (or a gym’s fingerprint device), and daily-wage payslips start from the days present.'],
                    ['wallet', 'Advances & deposits', 'Take money ahead for a booking or a job, and use it on the final bill.'],
                    ['id', 'Client notes', 'Allergies, preferences, measurements or a vehicle’s history, kept on the customer’s page.'],
                    ['bell', 'WhatsApp reminders', 'Appointment, renewal and service-due reminders, sent automatically or with one tap.'],
                    ['chat', 'Broadcasts', 'One message to many customers: new timings, an offer or a diet plan.'],
                    ['box', 'Products on the same bill', 'Sell hair products, supplements or spare parts with the service, from stock costed batch by batch.'],
                ],
                'spotlights' => [
                    ['Commission that matches payroll', 'The commission each person sees is the figure payroll uses. A return or a cancelled bill after the month is paid comes off the next payslip as an adjustment, never by editing a paid one.'],
                ],
                'related' => ['payroll', 'pos', 'udhaar'],
            ],
        ];
    }

    /**
     * One page per business type, in menu order within each group.
     *
     * @return array<string, array<string, mixed>> SiteFeatures::all() shape plus group, category, short and optional pair
     */
    public static function all(): array
    {
        return [
            'mobile-shop' => [
                'group' => 'retail-shops',
                'category' => BusinessCategory::MobileElectronics,
                'name' => 'Mobile & Electronics Shops',
                'short' => 'Mobile shops',
                'seo_title' => 'Mobile Shop Software Pakistan: IMEI, Repairs & Loads',
                'summary' => 'IMEI checks, warranty, instalments, buybacks, repairs, SIMs, loads, bills and NADRA — each with its own screen and income.',
                'icon' => 'device',
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
                    ['sparkles', 'Counter services', 'Any other service you offer — photocopies, installations, setup — with its own categories and pricing.'],
                ],
                'spotlights' => [
                    ['Mobile & electronics shops', 'Phones with IMEIs, accessories, SIMs, loads, bills, NADRA and repairs — each with its own screen and fee income.'],
                ],
                'related' => ['cash-balances', 'pos', 'udhaar'],
            ],
            'pharmacy' => [
                'group' => 'retail-shops',
                'category' => BusinessCategory::Pharmacy,
                'name' => 'Pharmacy & Medical Store',
                'short' => 'Pharmacies',
                'seo_title' => 'Pharmacy Software in Pakistan: Medical Store POS',
                'summary' => 'Salt search with substitutes, batch numbers and expiry, box-strip-tablet selling and a prescription & controlled-drug register.',
                'icon' => 'heart',
                'eyebrow' => 'Pharmacies & medical stores',
                'title' => 'Run your medical store by salt, batch and expiry',
                'lead' => 'Find a medicine by its salt and offer a same-salt substitute, sell a box, a strip or a single tablet, and let the batch closest to expiry go first — with every prescription recorded.',
                'description' => 'Pharmacy software for Pakistani medical stores: search by salt or generic name, substitutes, DRAP numbers, batch and expiry tracking, strip and box selling and a prescription register.',
                'items' => [
                    ['eye', 'Search by salt', 'Search by generic (salt) name as well as brand, and see the same-salt substitutes you have in stock.'],
                    ['id', 'Medicine details', 'Keep each medicine’s generic name and DRAP registration number with the product.'],
                    ['clock', 'Expiry first, from day one', 'The batch closest to expiry sells first and expired stock can’t be sold — on from the day you sign up as a pharmacy.'],
                    ['receipt', 'Batch numbers', 'Record the batch number on every Stock In, and see it in stock history and on the expiry list.'],
                    ['box', 'Box, strip or tablet', 'Sell a whole box, a strip or a single tablet, each at its own price, while stock counts every tablet.'],
                    ['shield', 'Prescription register', 'Mark medicines as prescription-only or controlled; each sale gets a numbered register entry with prescriber and patient.'],
                    ['photo', 'Prescription photos & emergencies', 'Attach a photo of the prescription, kept private. No prescriber? It’s recorded as an emergency, with the reason.'],
                    ['chart', 'Import your medicine list', 'Bring medicines in from Excel with generic name, DRAP number and prescription columns.'],
                ],
                'spotlights' => [
                    ['Returns and udhaar too', 'Take back unopened medicine against its invoice for a refund or store credit, and keep an udhaar khata for regular customers with WhatsApp reminders.'],
                ],
                'related' => ['stock', 'returns-exchange', 'udhaar'],
            ],
            'kiryana-store' => [
                'group' => 'retail-shops',
                'category' => BusinessCategory::Kiryana,
                'name' => 'Kiryana & Grocery Store',
                'short' => 'Kiryana stores',
                'seo_title' => 'Kiryana Store Software: Grocery POS & Udhaar App',
                'summary' => 'Carton, pack and piece selling, expiry on from day one, and udhaar with a customer ledger link and JazzCash payments.',
                'icon' => 'store',
                'eyebrow' => 'Kiryana & general stores',
                'title' => 'The kiryana counter, without the khata register',
                'lead' => 'Scan and sell in seconds, buy by the carton and sell by the pack or piece, and keep every regular customer’s udhaar on their own link — with reminders on WhatsApp and payments by JazzCash.',
                'description' => 'Kiryana store software for Pakistan: fast barcode billing, carton and pack selling, expiry tracking, an udhaar khata with WhatsApp reminders, a customer ledger link and JazzCash payments.',
                'items' => [
                    ['cart', 'Fast barcode billing', 'Scan or search and the bill is ready, with partial payments and udhaar on the same screen.'],
                    ['box', 'Carton, pack or piece', 'Buy by the carton, then sell a whole pack or a single piece, each at its own price.'],
                    ['clock', 'Expiry from day one', 'The stock closest to expiry sells first, and expired goods can’t be billed by mistake.'],
                    ['wallet', 'Udhaar khata', 'Every regular customer’s balance itemised by sale, with polite WhatsApp reminders.'],
                    ['eye', 'Customer ledger link', 'Each customer gets a private link to check their own balance and dues any time.'],
                    ['phone', 'JazzCash payments', 'Customers pay their udhaar online into your own JazzCash merchant account.'],
                    ['bell', 'Low stock alerts', 'An email when a product runs low, so the shelf doesn’t go empty.'],
                    ['calculator', 'Loose items by weight', 'Sell daal, rice or sugar by the kg with quantities like 1.5 — a free feature we switch on when you ask.'],
                ],
                'spotlights' => [
                    ['Promotions and returns built in', 'Run a weekend discount on chosen items with start and end dates, and take back a wrong item against its bill — both free on every plan.'],
                ],
                'related' => ['udhaar', 'stock', 'cash-balances'],
            ],
            'clothing-shoes' => [
                'group' => 'retail-shops',
                'category' => BusinessCategory::Clothing,
                'name' => 'Clothing & Shoe Shops',
                'short' => 'Clothing & shoes',
                'seo_title' => 'Clothing & Shoe Shop POS Software in Pakistan',
                'summary' => 'Sizes and colours with their own barcodes, a size-by-colour Stock In grid, labels, exchanges, store credit and season sales.',
                'icon' => 'layers',
                'eyebrow' => 'Clothing & shoes',
                'title' => 'Every size and colour, counted and labelled',
                'lead' => 'Receive a new design in every size and colour from one grid, print its barcode labels, and handle an exchange for another size in one step — for garments, shoes and fabric alike.',
                'description' => 'Clothing and shoe shop POS software for Pakistan: sizes and colours with barcodes, a size-by-colour Stock In grid, label printing, exchanges, store credit and season sale promotions.',
                'notice' => 'Sizes and colours, and fabric sold by the metre or gaz, are free features we switch on for your shop. Just ask when you sign up.',
                'items' => [
                    ['layers', 'Sizes & colours', 'One design in every size and colour, each with its own barcode, price and stock count.'],
                    ['box', 'Size-by-colour grid', 'Enter a whole delivery in one size-by-colour grid instead of line by line.'],
                    ['qr', 'Barcode labels', 'Print labels for everything a delivery brought in, straight after saving it.'],
                    ['check', 'Exchange for another size', 'Swap a size or colour in one step; the customer pays or gets back only the difference.'],
                    ['wallet', 'Store credit', 'No cash refund? Give store credit the customer spends on their next visit.'],
                    ['sparkles', 'Season sales', 'Percent or flat off on chosen designs or categories, with start and end dates.'],
                    ['receipt', 'Returns & credit notes', 'Take items back against the original bill, with a credit note PDF.'],
                    ['calculator', 'Fabric by the metre', 'Sell unstitched fabric by the metre or gaz, down to fractions.'],
                ],
                'spotlights' => [
                    ['Garments and shoes alike', 'Men, Women and Kids categories are ready when you sign up as a clothing & shoes shop, and shoe sizes work exactly like garment sizes.'],
                ],
                'related' => ['returns-exchange', 'stock', 'pos'],
            ],

            'wholesaler' => [
                'group' => 'wholesale-distribution',
                'category' => BusinessCategory::Wholesaler,
                'name' => 'Wholesalers',
                'short' => 'Wholesalers',
                'seo_title' => 'Wholesale Billing Software Pakistan: Credit & Aging',
                'summary' => 'Wholesale price lists, credit limits and dues aging, a cheque and PDC register, purchase returns and bonus stock.',
                'icon' => 'building',
                'eyebrow' => 'Wholesalers',
                'title' => 'Wholesale billing that knows every customer’s rate and limit',
                'lead' => 'Bill trade customers at their wholesale price, stop a sale that breaks their credit limit and see exactly who is paying late — with cheques, purchase returns and bonus stock handled too.',
                'description' => 'Wholesale billing software for Pakistan: wholesale price per customer, last rate, credit limits, payment terms, dues aging, a cheque and PDC register, purchase returns and bonus quantity.',
                'items' => [
                    ['tag', 'Wholesale price list', 'A wholesale price on every product, picked automatically for trade customers; walk-ins pay retail.'],
                    ['eye', 'Last rate', 'The rate this customer last paid for a product, shown on the bill with a one-tap Use.'],
                    ['box', 'Cartons and pieces', 'Sell by the carton, the pack or the piece, each at its own price, while stock counts every piece.'],
                    ['shield', 'Credit limit with approval', 'Over the limit? The sale waits for the owner’s approval, and the reason is kept with the bill.'],
                    ['chart', 'Terms & dues aging', 'Due dates from each customer’s terms, and an aging report from 0–30 to 90+ days.'],
                    ['banknotes', 'Cheques & PDC', 'Post-dated cheques from customers and to suppliers, tracked until they clear; a bounce puts the balance back.'],
                    ['return', 'Purchase returns', 'Send goods back to the supplier from the batch they came in, with a debit note that comes off what you owe.'],
                    ['gift', 'Bonus quantity', 'Record the free pieces a supplier adds to a delivery; their cost is spread over every piece.'],
                ],
                'spotlights' => [
                    ['Your price list, applied at the counter', 'Set a wholesale price once per product. Trade customers get it on every bill, walk-ins pay retail, and you can still type a special rate on any line — with a warning if it’s below cost.'],
                ],
                'related' => ['purchase-orders', 'udhaar', 'reports'],
            ],
            'trader' => [
                'group' => 'wholesale-distribution',
                'category' => BusinessCategory::Trader,
                'name' => 'Traders',
                'short' => 'Traders',
                'seo_title' => 'Trading Business Software: Rates, Credit & Contra',
                'summary' => 'Wholesale rates, credit control and aging, cheques both ways, purchase returns, bonus stock and contra with parties you buy from and sell to.',
                'icon' => 'arrows',
                'eyebrow' => 'Traders',
                'title' => 'Buy from them, sell to them, settle it in one entry',
                'lead' => 'Traders deal both ways with the same parties. HisabOKitab keeps wholesale rates and credit limits for buyers, tracks cheques both ways, and sets off what a party owes you against what you owe them.',
                'description' => 'Software for trading businesses in Pakistan: wholesale rates per customer, credit limits, dues aging, cheques both ways, purchase returns, bonus quantity and contra settlement between parties.',
                'items' => [
                    ['tag', 'Wholesale rates', 'A wholesale rate on every product, picked automatically for trade customers, with the last rate one tap away.'],
                    ['arrows', 'Contra settlement', 'Link a supplier to their customer account and set off what each owes the other, with no cash changing hands.'],
                    ['banknotes', 'Cheques both ways', 'Cheques you hold and cheques you’ve given, each paying nothing until it clears.'],
                    ['shield', 'Credit limits', 'A sale past a customer’s limit waits for the owner’s approval, with the reason recorded.'],
                    ['chart', 'Dues aging', 'Who is late, by how long, with bills and cash udhaar kept apart.'],
                    ['return', 'Purchase returns', 'Return goods to the supplier with a debit note, taken off what you owe or paid back.'],
                    ['gift', 'Bonus quantity', 'Free pieces on a delivery recorded with the Stock In, spreading the cost.'],
                    ['users', 'Party statements', 'A running statement for every customer and supplier, for any dates.'],
                ],
                'spotlights' => [
                    ['Contra, done properly', 'A contra only ever sets off sale bills against stock bills, never cash udhaar, and can be undone — so both ledgers stay right.'],
                ],
                'related' => ['purchase-orders', 'udhaar', 'cash-balances'],
            ],
            'distributor' => [
                'group' => 'wholesale-distribution',
                'category' => BusinessCategory::Distributor,
                'name' => 'Distributors',
                'short' => 'Distributors',
                'seo_title' => 'FMCG Distribution Software Pakistan: Routes & Schemes',
                'summary' => 'Trade prices, sales orders, load sheets, routes and bookers, trade schemes and claims, and credit and recovery control for FMCG distributors.',
                'icon' => 'truck',
                'eyebrow' => 'Distributors',
                'title' => 'From booked order to delivered, collected and claimed',
                'lead' => 'Book orders route by route, hold the stock, send the van out with a printed load sheet, settle what came back — and claim every trade scheme’s free goods back from the company.',
                'description' => 'FMCG distribution software for Pakistan: trade prices, sales orders with held stock, load sheets and delivery challans, routes and bookers, trade schemes and claims, credit limits and aging.',
                'items' => [
                    ['receipt', 'Sales orders', 'Book an order for a shop and its stock is held, so the counter can’t sell it twice before delivery.'],
                    ['truck', 'Load sheets & challans', 'One van run: a loading list, stops in visiting order and a challan for each delivery, then settle what came back.'],
                    ['route', 'Routes & visiting order', 'Put every shop on a route in the order you visit them, each route with its booker.'],
                    ['users', 'Sales by booker & route', 'Record who booked each bill and compare sales by booker, route and brand.'],
                    ['gift', 'Trade schemes', '10 + 1 free and similar schemes, added to the bill and the order automatically while they run.'],
                    ['ticket', 'Scheme claims', 'Claim the free goods back from the company invoice by invoice, settled as credit or cash.'],
                    ['tag', 'Trade prices', 'Retailers get your trade price automatically, with the last rate one tap away.'],
                    ['shield', 'Credit limits', 'A bill past a retailer’s limit waits for the owner’s approval.'],
                    ['chart', 'Credit & recovery control', 'Due dates from each retailer’s terms and an aging report from 0–30 to 90+ days.'],
                    ['banknotes', 'Cheques & PDC', 'Post-dated cheques tracked until they clear; a bounce puts the balance back.'],
                    ['return', 'Purchase returns', 'Expired or damaged goods back to the company with a debit note.'],
                    ['box', 'Bonus quantity', 'Free goods on a company delivery recorded with the Stock In, spreading the cost.'],
                ],
                'spotlights' => [
                    ['How a day runs', 'Orders are booked for each shop on a route, their stock is held, the van leaves with a load sheet, deliveries become bills with a challan, and the load sheet is settled with what came back.'],
                    ['What it doesn’t do yet', 'There’s no order-booker mobile app or van stock count yet: booked orders are entered in HisabOKitab, and each van run is a load sheet.'],
                ],
                'related' => ['purchase-orders', 'reports', 'multi-branch'],
            ],

            'social-seller' => [
                'group' => 'online-sellers',
                'category' => BusinessCategory::SocialSeller,
                'name' => 'Social Media Sellers',
                'short' => 'Social sellers',
                'seo_title' => 'Instagram & Facebook Seller Software: COD & Couriers',
                'summary' => 'Orders from Instagram, Facebook and WhatsApp with delivery charges, COD parcels, courier payouts, RTO returns and payment links.',
                'icon' => 'chat',
                'eyebrow' => 'Instagram, Facebook & WhatsApp sellers',
                'title' => 'Sell from your DMs, keep the books like a business',
                'lead' => 'Take the order from Instagram, Facebook or WhatsApp, add the delivery charge, send it by courier and know which parcels the courier still owes you for — with fake-order warnings and returns handled.',
                'description' => 'Software for Instagram, Facebook and WhatsApp sellers in Pakistan: orders with delivery charges, COD parcels, courier payout reconciliation, RTO returns, refusal warnings and payment links.',
                'items' => [
                    ['chat', 'Orders from every chat', 'Ring up a DM order in seconds, marked by channel, with the buyer found or added by phone number.'],
                    ['truck', 'Delivery charge on the bill', 'Address, city and delivery charge on the order; the courier collects goods and delivery together.'],
                    ['map', 'Courier parcels', 'Book Leopards or PostEx on plans with courier booking, or type in a tracking number from any courier.'],
                    ['banknotes', 'COD reconciliation', 'Record each courier payout against its statement: every parcel paid, fees and tax to expenses.'],
                    ['return', 'RTO handling', 'A refused parcel comes back into stock, damaged pieces are written off and the return charge is noted.'],
                    ['bell', 'Fake-order warnings', 'A phone number that refused parcels before is flagged on the next order.'],
                    ['link', 'Payment links', 'Send a JazzCash link for one order; the payment marks that order paid.'],
                    ['star', 'Review requests', 'Ask for a rating on WhatsApp once a parcel is delivered.'],
                ],
                'spotlights' => [
                    ['Your WhatsApp, posts and ads, too', 'On plans that include them, the same account runs a WhatsApp ordering chatbot, designed Facebook and Instagram posts, and Meta ads that open a chat with you.'],
                ],
                'related' => ['courier-booking', 'whatsapp-ordering', 'social-posting'],
            ],
            'daraz-seller' => [
                'group' => 'online-sellers',
                'category' => BusinessCategory::DarazSeller,
                'name' => 'Daraz Sellers',
                'short' => 'Daraz sellers',
                'seo_title' => 'Daraz Seller Inventory Software & Payout Reconciliation',
                'summary' => 'Import Daraz orders and payout statements from Seller Center, match SKUs once, and see Daraz’s commission and fees on every order.',
                'icon' => 'cart',
                'notice' => 'New: Daraz imports read the order and statement files Seller Center exports. They haven’t been run on a real seller’s file yet, so send us yours and we’ll check it with you first.',
                'eyebrow' => 'Daraz sellers',
                'title' => 'Your Daraz orders and payouts, reconciled',
                'lead' => 'Upload the orders file from Seller Center and each order is sold out of your stock. When Daraz pays, upload the statement: commission, fees and tax go to expenses, and only what reached your account shows as cash.',
                'description' => 'Daraz seller inventory software for Pakistan: import Seller Center order files and payout statements, match Seller SKUs once, reconcile commission, fees and tax, and keep one stock for every channel.',
                'items' => [
                    ['receipt', 'Orders from Seller Center', 'Upload the orders file as it comes; every order whose Seller SKU matches is sold from your stock at once.'],
                    ['tag', 'Match SKUs once', 'Pick the product for an unknown Seller SKU one time, and every later file matches it by itself.'],
                    ['arrows', 'Status from newer files', 'A newer file moves orders to delivered or returned; the same file twice changes nothing.'],
                    ['banknotes', 'Payout statements', 'Upload the account statement when Daraz pays, check it against the file’s own total, and record it as one payout.'],
                    ['calculator', 'Commission, fees & tax', 'Each order’s charges go to expenses and the tax Daraz held is kept apart, so your profit is the real one.'],
                    ['wallet', 'Never customer udhaar', 'What Daraz owes you for delivered orders is owed by Daraz, not shown as a customer’s udhaar.'],
                    ['return', 'Returned orders', 'Returns from the file put the stock back, with damaged pieces written off.'],
                    ['box', 'One stock for every channel', 'Daraz, WhatsApp, social and counter orders all sell from the same stock count.'],
                ],
                'spotlights' => [
                    ['What isn’t covered yet', 'Stock sent to a Daraz warehouse (Fulfilled by Daraz) isn’t counted apart from the rest, and Daraz’s stock figure isn’t updated for you: change it in Seller Center when you restock.'],
                ],
                'related' => ['stock', 'courier-booking', 'reports'],
            ],
            'online-brand' => [
                'group' => 'online-sellers',
                'category' => BusinessCategory::OnlineBrand,
                'name' => 'Online Brands',
                'short' => 'Online brands',
                'seo_title' => 'Shopify Inventory Software Pakistan for Online Brands',
                'summary' => 'Connect Shopify or WooCommerce, keep one stock across your store, Daraz and social, and reconcile COD payouts and returns.',
                'icon' => 'sparkles',
                'notice' => 'New: Shopify and WooCommerce connections come with plans that include the online store connection, and haven’t connected a live store yet — we’ll set up your first connection with you.',
                'eyebrow' => 'Small online brands',
                'title' => 'Your store, your stock and your cash, in step',
                'lead' => 'Connect your Shopify or WooCommerce store so its stock follows HisabOKitab and its orders land in one inbox, ship by courier and reconcile every payout — while WhatsApp, Daraz and Instagram orders sell from the same stock.',
                'description' => 'Shopify and WooCommerce inventory software for Pakistani online brands: live stock to your store, orders into one inbox, COD courier payouts, RTO returns, delivery charges and payment links.',
                'items' => [
                    ['globe', 'Shopify & WooCommerce', 'Connect your store from Settings, no developer needed: stock changes in HisabOKitab update your store.'],
                    ['check', 'One order inbox', 'Website orders land next to WhatsApp orders; accepted ones become sales from the right batch.'],
                    ['lock', 'No overselling', 'Stock in pending website, WhatsApp and booked orders is held back from every channel.'],
                    ['receipt', 'Any other website', 'Built your own site? Your developer connects it through our documented API and signed webhooks.'],
                    ['banknotes', 'COD payouts', 'Courier payouts recorded against their statements, net of fees and tax.'],
                    ['return', 'Returns & RTO', 'Returned parcels back into stock, damaged pieces written off, return charges noted.'],
                    ['link', 'Payment links', 'A JazzCash link for one order, so a buyer can pay before dispatch.'],
                    ['layers', 'Sizes & colours', 'Each design in every size and colour with its own barcode — a free feature we switch on when you ask.'],
                ],
                'spotlights' => [
                    ['No website yet?', 'Send us a website request from inside the app and we’ll talk you through the options.'],
                ],
                'related' => ['online-store', 'courier-booking', 'stock'],
            ],

            'repair-workshop' => [
                'group' => 'service-businesses',
                'category' => BusinessCategory::RepairWorkshop,
                'name' => 'Repair Workshops',
                'short' => 'Workshops',
                'seo_title' => 'Workshop Management & Job Card Software Pakistan',
                'summary' => 'Job cards for auto, bike, AC and appliance repair: estimates, parts from stock, a ready message on WhatsApp and service-due reminders.',
                'icon' => 'wrench',
                'eyebrow' => 'Auto, bike, AC & appliance workshops',
                'title' => 'Job cards from drop-off to delivery',
                'lead' => 'Open a job card when the car, bike, AC or appliance comes in, get the customer’s OK on the estimate, use parts from stock, tell them on WhatsApp when it’s ready — and remind them when the next service is due.',
                'description' => 'Workshop management software for Pakistan: job cards for auto, bike, AC and appliance repair, estimates, parts from stock, technician commission, advances, warranty and service reminders.',
                'items' => [
                    ['clipboard', 'Job cards', 'Each unit with the details its kind needs — vehicle number, model or serial — plus the fault, technician and promised date.'],
                    ['calculator', 'Estimates & approval', 'Parts and labour lines with the customer’s OK recorded, and asked for again if the price changes.'],
                    ['box', 'Parts from stock', 'Parts are held for the job and come out of stock when it’s billed, costed from the right batch.'],
                    ['map', 'Site visits', 'Work at the customer’s place, with the address and a visit charge on the job.'],
                    ['bell', 'Ready on WhatsApp', 'Tell the customer their unit is ready with one message, sent for you or with one tap.'],
                    ['clock', 'Service-due reminders', 'Set the next service in days; the customer is reminded on WhatsApp when it’s due.'],
                    ['users', 'Technician commission', 'Each technician’s commission on the labour they did, worked out for payroll.'],
                    ['wallet', 'Advances', 'Take an advance on a big job and use it on the final bill.'],
                ],
                'spotlights' => [
                    ['Warranty from the day it leaves', 'Billing the job prints parts and labour on one invoice, and the warranty starts on the day of delivery.'],
                    ['Phone repair counters', 'Mobile and laptop repair counters keep the simple Repairs screen of the mobile shop setup. Workshops get full job cards: several units, parts and labour, estimates and technicians.'],
                ],
                'related' => ['pos', 'payroll', 'udhaar'],
            ],
            'salon' => [
                'group' => 'service-businesses',
                'category' => BusinessCategory::Salon,
                'name' => 'Salons & Barbers',
                'short' => 'Salons',
                'seo_title' => 'Salon Software Pakistan: Salon POS & Appointments',
                'summary' => 'Appointment booking and a walk-in queue, a service menu, staff commission, packages, gift vouchers and reminders for salons, barbers and beauty parlours.',
                'icon' => 'scissors',
                'eyebrow' => 'Salons, barbers & beauty parlours',
                'title' => 'Every chair booked, every stylist paid right',
                'lead' => 'Book clients by stylist without double-booking, give walk-ins a queue number, bill services and products together, and work out each stylist’s commission for payroll.',
                'description' => 'Salon software for Pakistan: salon POS with appointment booking by stylist, a walk-in queue, service menu, staff commission, packages, gift vouchers and reminders for beauty parlours and barbers.',
                'items' => [
                    ['calendar', 'Appointment booking', 'The day in a column per stylist; a booking that overlaps the same stylist’s is refused.'],
                    ['clock', 'Walk-in queue', 'Walk-ins get the day’s next queue number, next to the bookings.'],
                    ['sparkles', 'Service menu & one bill', 'Haircut, colour or facial with its price and time, billed with the products used or sold.'],
                    ['users', 'Staff commission', 'A % or a fixed amount per service for each stylist, after discounts and returns, onto the payslip.'],
                    ['ticket', 'Packages & memberships', 'Sell a five-facial package or a monthly membership, used visit by visit.'],
                    ['gift', 'Gift vouchers & deposits', 'Vouchers with their own code, and deposits taken ahead for bridal or party bookings.'],
                    ['id', 'Client notes', 'Hair type, allergies and preferences on each client’s page.'],
                    ['bell', 'Reminders & broadcasts', 'Appointment reminders on WhatsApp, and one message to all your clients for an offer.'],
                ],
                'pair' => ['Salon and spa under one roof?', 'Sign up as a salon and tick “Spa” too: both trades’ tools run in one business, with one service menu, one calendar and one team.'],
                'related' => ['payroll', 'pos', 'udhaar'],
            ],
            'spa' => [
                'group' => 'service-businesses',
                'category' => BusinessCategory::Spa,
                'name' => 'Spas & Beauty Clinics',
                'short' => 'Spas',
                'seo_title' => 'Spa & Beauty Clinic Software: Bookings & Packages',
                'summary' => 'Treatment bookings by therapist, session packages, memberships, gift vouchers, deposits and client notes for spas and beauty clinics.',
                'icon' => 'heart',
                'eyebrow' => 'Spas & beauty clinics',
                'title' => 'Treatments booked, packages tracked, therapists paid',
                'lead' => 'Book each treatment with the right therapist for the right time, sell packages of sessions and track what’s left, take deposits and sell gift vouchers — with every client’s notes in one place.',
                'description' => 'Spa and beauty clinic software for Pakistan: treatment bookings by therapist, session packages and memberships, gift vouchers, deposits, client notes, therapist commission and WhatsApp reminders.',
                'items' => [
                    ['calendar', 'Bookings by therapist', 'Each therapist’s day at a glance; a treatment that overlaps another booking is refused.'],
                    ['clock', 'Treatment times', 'Each treatment’s time sizes its booking on the calendar.'],
                    ['ticket', 'Session packages', 'A package of sessions on the treatments you choose, optionally to use within a time; each visit uses one.'],
                    ['arrows', 'Memberships', 'Monthly or yearly memberships that renew from the day they end, and freeze when a client travels.'],
                    ['gift', 'Gift vouchers', 'Sold now with their own code and spent later by whoever holds them.'],
                    ['wallet', 'Deposits', 'Money taken ahead for a booking, used on the final bill.'],
                    ['id', 'Client notes', 'Skin type, allergies and treatment history on the client’s page.'],
                    ['users', 'Therapist commission', 'Commission per treatment for each therapist, worked out for payroll.'],
                ],
                'pair' => ['Spa with a salon?', 'Sign up as a spa and tick “Salon” too: treatments, haircuts and packages run in one business, from one menu and one calendar.'],
                'related' => ['payroll', 'pos', 'udhaar'],
            ],
            'gym' => [
                'group' => 'service-businesses',
                'category' => BusinessCategory::Gym,
                'name' => 'Gyms & Fitness Clubs',
                'short' => 'Gyms',
                'seo_title' => 'Gym Management Software Pakistan: Fees & Biometric',
                'summary' => 'Memberships with renewals and freezes, fee reminders, fingerprint (ZKTeco) or QR check-in, classes, trainer commission and staff attendance.',
                'icon' => 'dumbbell',
                'notice' => 'New: fingerprint check-in works with ZKTeco-type devices that support Cloud Server (ADMS) mode. It hasn’t run on a real device yet, so we’ll set up your first one with you. QR member cards work with any scanner.',
                'eyebrow' => 'Gyms & fitness clubs',
                'title' => 'Members in, fees collected, a door that knows who’s paid',
                'lead' => 'Sell monthly memberships and packages, check members in by fingerprint or QR card, remind them before the fee is due — and the fingerprint device drops members whose membership has ended.',
                'description' => 'Gym management software for Pakistan: memberships, renewals and freezes, gym fee reminders on WhatsApp, biometric attendance with ZKTeco fingerprint devices, QR check-in and trainer commission.',
                'items' => [
                    ['arrows', 'Memberships & renewals', 'Monthly, quarterly or yearly; a renewal carries on from the day the current one ends.'],
                    ['clock', 'Freeze', 'Pause a membership for travel or illness, up to the days you allow; the end date moves with it.'],
                    ['finger', 'Fingerprint check-in', 'ZKTeco-type devices send check-ins over the internet; lapsed members come off the device each night.'],
                    ['qr', 'QR member cards', 'Print a card for each member and check them in with any QR scanner.'],
                    ['bell', 'Fee reminders', 'Renewal reminders on WhatsApp before a membership ends, with Expiring and Expired lists.'],
                    ['calendar', 'Classes & PT sessions', 'Classes with a set number of places, and personal training sessions from packages.'],
                    ['wallet', 'Dues at the desk', 'Checking a member in shows what they still owe.'],
                    ['users', 'Trainer commission & attendance', 'Trainers’ commission for payroll, and staff clocking in on the same device.'],
                ],
                'spotlights' => [
                    ['The device and the fee register, talking', 'In most gyms the fingerprint machine and the fee register never meet. Here the device checks members in, and members whose membership has ended are taken off it until they renew.'],
                ],
                'pair' => ['Gym with personal training?', 'Sign up as a gym and tick “Personal training” too: memberships, PT packages and trainer commission all run from one business.'],
                'related' => ['payroll', 'udhaar', 'pos'],
            ],
            'personal-training' => [
                'group' => 'service-businesses',
                'category' => BusinessCategory::PersonalTraining,
                'name' => 'Personal Trainers & Studios',
                'short' => 'Trainers',
                'seo_title' => 'Personal Trainer & Fitness Studio Software',
                'summary' => 'Session packages, client bookings and small classes, QR check-in, client notes and WhatsApp broadcasts for personal trainers and fitness studios.',
                'icon' => 'users',
                'eyebrow' => 'Personal trainers & fitness studios',
                'title' => 'Sessions sold, booked and counted',
                'lead' => 'Sell 12-session packages or monthly plans, book each client into your calendar or a small class, count every session used — and keep diet plans and progress notes on the client’s page.',
                'description' => 'Personal trainer and fitness studio software for Pakistan: session packages and memberships, client bookings and small classes, QR check-in, client notes, WhatsApp broadcasts and trainer commission.',
                'items' => [
                    ['ticket', 'Session packages', 'A number of sessions to use within a time; each booking uses one, and you see what’s left.'],
                    ['calendar', 'Bookings & small classes', 'One-to-one sessions or a class with a set number of places, with no double-booking.'],
                    ['qr', 'QR check-in', 'A printed card for each client, checked in with any QR scanner.'],
                    ['id', 'Client notes', 'Goals, injuries, diet plans and progress on each client’s page.'],
                    ['chat', 'Broadcasts', 'New timings or a diet plan to all your clients in one message.'],
                    ['bell', 'Renewal reminders', 'A WhatsApp reminder before a package or membership runs out.'],
                    ['arrows', 'Memberships with freeze', 'Monthly plans that renew from their end date, and pause when a client travels.'],
                    ['users', 'Trainer commission', 'Each trainer’s commission on the sessions they took, for payroll.'],
                ],
                'pair' => ['Training inside a gym?', 'Sign up as personal training and tick “Gym” too: memberships, check-in and PT packages run together in one business.'],
                'related' => ['payroll', 'udhaar', 'pos'],
            ],
        ];
    }

    /**
     * A business-type page with its group's badge and notice filled in when
     * it doesn't set its own.
     */
    public static function find(string $slug): ?array
    {
        $page = self::all()[$slug] ?? null;
        if ($page === null) {
            return null;
        }

        $group = self::groups()[$page['group']];

        return $page + array_filter(['badge' => $group['badge'] ?? null, 'notice' => $group['notice'] ?? null]);
    }

    public static function findGroup(string $slug): ?array
    {
        return self::groups()[$slug] ?? null;
    }

    /** @return array<string, array<string, mixed>> the pages of one group, each through find() */
    public static function pagesIn(string $group): array
    {
        $pages = array_filter(self::all(), fn (array $page) => $page['group'] === $group);

        return array_combine(array_keys($pages), array_map(fn (string $slug) => self::find($slug), array_keys($pages)));
    }

    /** The other page(s) for trades one business may run together (salon + spa, gym + personal training). */
    public static function partnerOf(string $slug): ?string
    {
        $partners = self::find($slug)['category']->combinesWith();

        foreach (self::all() as $other => $page) {
            if (in_array($page['category'], $partners, true)) {
                return $other;
            }
        }

        return null;
    }

    /** @return array<int, string> group overview slugs, in menu order */
    public static function groupSlugs(): array
    {
        return array_keys(self::groups());
    }

    /** @return array<int, string> business-type page slugs */
    public static function pageSlugs(): array
    {
        return array_keys(self::all());
    }

    /** @return array<int, string> every /industries/{slug}: groups first, then business types */
    public static function slugs(): array
    {
        return [...self::groupSlugs(), ...self::pageSlugs()];
    }

    /**
     * The Industries menu, overview, home cards and footer: each group with
     * its business types, in display order.
     *
     * @return array<int, array{slug: string, url: string, name: string, icon: string, summary: string, badge: ?string,
     *     items: array<int, array{slug: string, url: string, name: string, short: string, icon: string, summary: string}>}>
     */
    public static function menu(): array
    {
        $pages = self::all();
        $menu = [];
        foreach (self::groups() as $groupSlug => $group) {
            $items = [];
            foreach ($pages as $slug => $page) {
                if ($page['group'] === $groupSlug) {
                    $items[] = ['slug' => $slug, 'url' => route('site.industry', $slug), 'name' => $page['name'], 'short' => $page['short'], 'icon' => $page['icon'], 'summary' => $page['summary']];
                }
            }

            $menu[] = [
                'slug' => $groupSlug,
                'url' => route('site.industry', $groupSlug),
                'name' => $group['name'],
                'icon' => $group['icon'],
                'summary' => $group['summary'],
                'badge' => $group['badge'] ?? null,
                'items' => $items,
            ];
        }

        return $menu;
    }
}
