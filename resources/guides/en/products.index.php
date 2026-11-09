<?php

// Guide: Products list, and the Add Product form / wizard that opens on it
// (section 'add-product', which the form's "How this works" link opens).

return [
    'title' => 'Products',
    'summary' => 'Everything you sell, with its price and stock. Add new products here. Stock that arrives later goes through Stock In.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Search', 'text' => 'Find a product by its name or model.'],
                ['label' => 'Search by barcode', 'text' => 'Scan or type a barcode in the same box.', 'feature' => 'barcode'],
                ['label' => 'Category', 'text' => 'Show one category only, for example Mobile or Accessory.'],
                ['label' => 'Stock', 'text' => 'Green is in stock, amber is Low, red is Out of stock. Archived products are hidden away (see Archive below).'],
                ['label' => 'Export', 'text' => 'Download the list as a PDF or an Excel file.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'The ⋯ menu on each product',
            'items' => [
                ['label' => 'View details', 'text' => 'The product page, with its batches and sales.'],
                ['label' => 'Edit', 'text' => 'Change the name, category, price and so on.'],
                ['label' => 'Print Label', 'text' => 'Print barcode labels for this product: how many copies, the layout, and whether to show the price.', 'feature' => 'barcode'],
                ['label' => 'Add to WhatsApp', 'text' => 'Lists the product in your WhatsApp catalogue. On WhatsApp means it is listed; tap it to take it off.', 'feature' => 'whatsapp_ordering'],
                ['label' => 'Archive', 'text' => 'Only for products with no stock left. It hides the product and it stops counting towards your plan. It comes back by itself when you stock it again.'],
                ['label' => 'Restore', 'text' => 'Brings an archived product back.'],
                ['label' => 'Delete', 'text' => 'Asks first, then removes the product for good.'],
            ],
        ],
        [
            'id' => 'more-actions',
            'heading' => 'The ⋯ next to Add Product',
            'items' => [
                ['label' => 'Add Variant Group', 'text' => 'Groups products that are the same thing in different sizes or colours, or makes new variants in one go.', 'feature' => 'variants'],
                ['label' => 'Import from Excel', 'text' => 'Adds many products at once from an Excel sheet. Download the template first and fill it in.', 'feature' => 'import'],
                ['label' => 'Generate missing barcodes', 'text' => 'Gives every product without a barcode its own unique one, ready to print on labels.', 'feature' => 'barcode'],
            ],
        ],
        [
            'id' => 'labels',
            'heading' => 'Printing many labels at once',
            'feature' => 'barcode',
            'intro' => 'Tick the box next to each product you want labels for, then press Print label sheet. Clear empties the selection.',
        ],
        [
            'id' => 'add-product',
            'heading' => 'Adding a product',
            'steps' => [
                'Press Add Product.',
                'Pick the Main Category and type the Product Name. The other fields change with the category: Brand, Model and IMEI / Serial for mobiles, Sub-Category for accessories.',
                'Enter the Selling Price. Cost Price is optional, but your profit reports need it.',
                ['text' => 'Press Next. Choose One product, or Sizes or colours to make a variant for each size and colour in one go.', 'feature' => 'variants'],
                'Enter Stock Qty, how many you have right now, then press Add Product.',
            ],
            'items' => [
                ['label' => 'Image', 'text' => 'Optional. A photo helps on the register, and on WhatsApp if you use it.'],
                ['label' => 'Sold By', 'text' => 'Piece for whole items. Kg, metre and the others let you sell amounts like 1.5.', 'feature' => 'measured_units'],
                ['label' => 'Sizes', 'text' => 'In Sizes or colours: separate them with commas, for example S, M, L. Colours works the same way. Each combination becomes a variant with its own stock and barcode.', 'feature' => 'variants'],
                ['label' => 'Shelf Life (days)', 'text' => 'Optional. Fills in the expiry date for you on Stock In.', 'feature' => 'expiry_tracking'],
                ['label' => 'Barcode', 'text' => 'Optional. Type or scan the barcode printed on the box, or press Generate for a new one.', 'feature' => 'barcode'],
                ['label' => 'Editing a product with stock', 'text' => 'Once stock has come in through Stock In, the price comes from those batches, so the price boxes are locked. Change the price on the Stock In entry instead.'],
            ],
        ],
    ],
];
