<?php

namespace App\Support;

/**
 * The marketing site's business-type pages, behind the header's "Industries"
 * menu, the /industries overview, the sitemap and the /industries/{slug}
 * routes. Pages use the same shape and template as SiteFeatures.
 *
 * Only built business types get a page: today that is the Shops (Retail)
 * group. The mobile shop page predates this list and stays at
 * /features/mobile-shop-services; menu() links to it there.
 *
 * Copy must only describe what these shops can use today. Variants and
 * weighed & measured items are free but off until switched on per shop, so
 * any page leaning on them says so.
 */
class SiteIndustries
{
    /**
     * @return array<string, array<string, mixed>> same shape as SiteFeatures::all()
     */
    public static function all(): array
    {
        return [
            'pharmacy' => [
                'name' => 'Pharmacy & Medical Store',
                'seo_title' => 'Pharmacy Software in Pakistan: Medical Store POS',
                'summary' => 'Salt search with substitutes, batch numbers and expiry, box-strip-tablet selling and a prescription & controlled-drug register.',
                'icon' => 'heart',
                'anchor' => 'pharmacy',
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
                'mockup' => null,
                'spotlights' => [
                    ['Returns and udhaar too', 'Take back unopened medicine against its invoice for a refund or store credit, and keep an udhaar khata for regular customers with WhatsApp reminders.'],
                ],
                'related' => ['stock', 'returns-exchange', 'udhaar'],
            ],
            'kiryana-store' => [
                'name' => 'Kiryana & Grocery Store',
                'seo_title' => 'Kiryana Store Software: Grocery POS & Udhaar App',
                'summary' => 'Carton, pack and piece selling, expiry on from day one, and udhaar with a customer ledger link and JazzCash payments.',
                'icon' => 'store',
                'anchor' => 'kiryana',
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
                'mockup' => null,
                'spotlights' => [
                    ['Promotions and returns built in', 'Run a weekend discount on chosen items with start and end dates, and take back a wrong item against its bill — both free on every plan.'],
                ],
                'related' => ['udhaar', 'stock', 'cash-balances'],
            ],
            'clothing-shoes' => [
                'name' => 'Clothing & Shoe Shops',
                'seo_title' => 'Clothing & Shoe Shop POS Software in Pakistan',
                'summary' => 'Sizes and colours with their own barcodes, a size-by-colour Stock In grid, labels, exchanges, store credit and season sales.',
                'icon' => 'layers',
                'anchor' => 'clothing',
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
                'mockup' => null,
                'spotlights' => [
                    ['Garments and shoes alike', 'Men, Women and Kids categories are ready when you sign up as a clothing & shoes shop, and shoe sizes work exactly like garment sizes.'],
                ],
                'related' => ['returns-exchange', 'stock', 'pos'],
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

    /**
     * The Industries menu and overview, in display order: the mobile shop
     * page (a feature page) first, then this class's pages.
     *
     * @return array<int, array{url: string, name: string, icon: string, summary: string, badge: ?string}>
     */
    public static function menu(): array
    {
        $mobile = SiteFeatures::find('mobile-shop-services');
        $items = [['url' => route('site.feature', 'mobile-shop-services'), 'name' => 'Mobile & Electronics Shops', 'icon' => $mobile['icon'], 'summary' => $mobile['summary'], 'badge' => null]];

        foreach (self::all() as $slug => $page) {
            $items[] = ['url' => route('site.industry', $slug), 'name' => $page['name'], 'icon' => $page['icon'], 'summary' => $page['summary'], 'badge' => $page['badge'] ?? null];
        }

        return $items;
    }
}
