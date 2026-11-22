<?php

namespace Tests\Feature;

use App\Actions\CreateStockIn;
use App\Actions\UpdateStockIn;
use App\Models\MainCategory;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\ReportQueries\StockInReport;
use App\Services\ProductGroupService;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Pharmacy and clothing, stage 1: generic (salt) search with substitutes, the
 * DRAP and batch numbers, size and colour kept apart on variants, the Stock In
 * size/colour grid and labels for a receipt just saved.
 */
class PharmacyClothingStage1Test extends TestCase
{
    use RefreshDatabase;

    protected function pharmacy(array $attributes = []): Shop
    {
        $shop = Shop::create($attributes + ['name' => 'Shifa Medical', 'business_category' => 'pharmacy', 'expiry_tracking_enabled' => true, 'barcode_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Medicines', 'slug' => 'medicines']);

        return $shop;
    }

    protected function clothing(array $attributes = []): Shop
    {
        $shop = Shop::create($attributes + ['name' => 'Libas', 'business_category' => 'clothing', 'variants_enabled' => true, 'barcode_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Clothing', 'slug' => 'clothing']);

        return $shop;
    }

    protected function medicine(Shop $shop, string $name, ?string $generic, int $stock = 0, float $price = 50): Product
    {
        return Product::create(['shop_id' => $shop->id, 'type' => 'medicines', 'name' => $name, 'generic_name' => $generic, 'price' => $price, 'cost_price' => $price * 0.8, 'stock_quantity' => $stock, 'details' => ['category' => null]]);
    }

    protected function group(Shop $shop, array $sizes, array $colours, float $price = 1500): ProductGroup
    {
        $service = app(ProductGroupService::class);
        $group = $service->createGroup($shop->id, 'Lawn Kurta', 'clothing', '');
        $service->addVariants($group, ProductGroupService::combineOptionPairs($sizes, $colours), $price, 900);

        return $group->fresh();
    }

    // ── Generic names ───────────────────────────────────────────────────

    public function test_generic_names_are_tidied_so_the_same_salt_matches(): void
    {
        $shop = $this->pharmacy();

        $a = $this->medicine($shop, 'Panadol', '  Paracetamol   500 MG ');
        $b = $this->medicine($shop, 'Calpol', 'Paracetamol 500mg');
        $c = $this->medicine($shop, 'Plain', '   ');

        $this->assertSame('Paracetamol 500mg', $a->fresh()->generic_name);
        $this->assertSame('Paracetamol 500mg', $b->fresh()->generic_name);
        $this->assertNull($c->fresh()->generic_name);
        $this->assertSame('Amoxicillin 250mg/5ml', Product::normalizeGenericName('Amoxicillin 250 mg/5 ml'));
        $this->assertSame('Vitamin D 5000iu', Product::normalizeGenericName('Vitamin D 5000 IU'));
        // A unit-looking word that isn't one is left alone.
        $this->assertSame('Zinc 20 grams', Product::normalizeGenericName('Zinc 20 grams'));
    }

    public function test_the_sale_search_finds_by_salt_and_offers_in_stock_substitutes(): void
    {
        $shop = $this->pharmacy();
        $this->medicine($shop, 'Panadol 500mg Tablet', 'Paracetamol 500mg', stock: 0);
        $this->medicine($shop, 'Calpol 500mg Tablet', 'Paracetamol 500mg', stock: 40);
        $this->medicine($shop, 'Panadol Extra', 'Paracetamol 500mg + Caffeine 65mg', stock: 10);
        $this->medicine($shop, 'Disprin', 'Aspirin 300mg', stock: 30);
        $other = $this->pharmacy(['name' => 'Other Pharmacy']);
        $this->medicine($other, 'Febrol 500mg', 'Paracetamol 500mg', stock: 99);
        $this->actingAs(User::where('shop_id', $shop->id)->first());

        // By brand: Panadol is out of stock, so Calpol shows as the same salt.
        Livewire::test('sales.create')
            ->set('search', 'Panadol')
            ->assertSee('Calpol 500mg Tablet')
            ->assertSee('Same salt')
            ->assertSee('Panadol Extra')
            ->assertDontSee('Febrol')
            ->assertDontSee('Disprin');

        // By salt: every in-stock brand of it, none marked as a substitute.
        Livewire::test('sales.create')
            ->set('search', 'paracetamol')
            ->assertSee('Calpol 500mg Tablet')
            ->assertSee('Panadol Extra')
            ->assertDontSee('Same salt')
            ->assertDontSee('Disprin');
    }

    public function test_a_shop_without_generic_names_searches_exactly_as_before(): void
    {
        $shop = $this->clothing();
        Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Blue Shawl', 'price' => 900, 'stock_quantity' => 3, 'details' => ['category' => null]]);
        Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Red Shawl', 'price' => 900, 'stock_quantity' => 3, 'details' => ['category' => null]]);

        Livewire::test('sales.create')->set('search', 'Blue')->assertSee('Blue Shawl')->assertDontSee('Red Shawl')->assertDontSee('Same salt');
    }

    public function test_the_products_list_search_matches_the_generic_name(): void
    {
        $shop = $this->pharmacy();
        $this->medicine($shop, 'Augmentin 625mg', 'Co-Amoxiclav 625mg');
        $this->medicine($shop, 'Brufen 400mg', 'Ibuprofen 400mg');

        Livewire::test('products.index')->set('search', 'amoxiclav')->assertSee('Augmentin 625mg')->assertDontSee('Brufen 400mg');
    }

    public function test_pharmacies_get_the_salt_and_drap_fields_and_other_shops_do_not(): void
    {
        $shop = $this->pharmacy();
        $product = $this->medicine($shop, 'Augmentin 625mg', null);

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->assertSee('Generic / Salt')
            ->set('generic_name', 'co-amoxiclav 625 mg')
            ->set('drap_reg_no', ' 008431 ')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame('co-amoxiclav 625mg', $product->generic_name);
        $this->assertSame('008431', $product->drap_reg_no);

        $this->clothing();
        Livewire::test('products.index')->call('openCreate')->assertDontSee('Generic / Salt')->assertDontSee('DRAP Reg. No.');
    }

    public function test_the_import_takes_generic_names_and_drap_numbers(): void
    {
        Storage::fake('local');
        $shop = $this->pharmacy();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Main Category', 'Selling Price', 'Generic Name', 'DRAP Reg No'],
            ['Panadol 500mg', 'Medicines', '3', 'Paracetamol 500 mg', '012345'],
            ['Gaviscon', 'Medicines', '450', '', ''],
        ], null, 'A1');
        $path = Storage::disk('local')->path('test-imports/medicines.xlsx');
        @mkdir(dirname($path), 0777, true);
        (new Xlsx($spreadsheet))->save($path);

        $service = app(ProductImportService::class);
        $preview = $service->preview($path, $shop->id);
        $this->assertTrue($preview['valid'], implode(' ', $preview['rowErrors']));
        $service->commit($path, $shop->id);

        $panadol = Product::where('name', 'Panadol 500mg')->sole();
        $this->assertSame('Paracetamol 500mg', $panadol->generic_name);
        $this->assertSame('012345', $panadol->drap_reg_no);
        $this->assertNull(Product::where('name', 'Gaviscon')->sole()->generic_name);
    }

    public function test_a_pharmacy_template_has_the_medicine_columns(): void
    {
        $response = app(ProductImportService::class)->templateResponse(false, false, false, true);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        ob_start();
        $response->sendContent();
        file_put_contents($path, ob_get_clean());

        $headers = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0)->rangeToArray('A1:K1')[0];
        $this->assertContains('Generic Name', $headers);
        $this->assertContains('DRAP Reg No', $headers);
        @unlink($path);
    }

    // ── Batch numbers ───────────────────────────────────────────────────

    public function test_stock_in_records_the_batch_number_and_it_keeps_lines_apart(): void
    {
        $shop = $this->pharmacy();
        $product = $this->medicine($shop, 'Augmentin 625mg', 'Co-Amoxiclav 625mg');

        $page = Livewire::test('stock-ins.create');

        foreach ([['A123', 10], ['B777', 5], ['A123', 2]] as [$batch, $quantity]) {
            $page->set('productId', (string) $product->id)->set('quantity', (string) $quantity)
                ->set('costPrice', '40')->set('sellingPrice', '50')->set('expiryDate', now()->addYear()->toDateString())
                ->set('batchNumber', $batch)->call('addLine')->assertHasNoErrors();
        }

        // The third line matches the first, batch and all, so they add up.
        $this->assertCount(2, $page->get('cart'));
        $page->call('save');

        $batches = StockIn::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertSame(['A123', 'B777'], $batches->pluck('batch_number')->all());
        $this->assertSame([12, 5], $batches->pluck('quantity')->all());
    }

    public function test_the_batch_field_only_shows_with_expiry_tracking_and_is_ignored_without_it(): void
    {
        $shop = $this->clothing();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Shawl', 'price' => 900, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        Livewire::test('stock-ins.create')
            ->assertDontSee('Batch No.')
            ->set('productId', (string) $product->id)->set('quantity', '2')->set('sellingPrice', '900')
            ->set('batchNumber', 'ZZ')->call('addLine')->call('save');

        $this->assertNull(StockIn::sole()->batch_number);
    }

    public function test_editing_a_batch_number_is_recorded_in_the_revision(): void
    {
        $shop = $this->pharmacy();
        $product = $this->medicine($shop, 'Brufen 400mg', 'Ibuprofen 400mg');
        $entry = CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 5.0, 'selling_price' => 8.0, 'batch_number' => ' LOT  9 ']], null, 'unpaid', null, now()->toDateString(), null, null)->sole();

        $this->assertSame('LOT 9', $entry->batch_number);

        UpdateStockIn::handle($entry, ['quantity' => 10, 'vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 5.0, 'selling_price' => 8.0, 'batch_number' => 'LOT 10'], null);

        $this->assertSame('LOT 10', $entry->fresh()->batch_number);
        $this->assertSame('LOT 9', ModuleRevision::where('revisionable_id', $entry->id)->sole()->snapshot['batch_number']);

        // An edit that doesn't mention it leaves it alone.
        UpdateStockIn::handle($entry->fresh(), ['quantity' => 9, 'vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 5.0, 'selling_price' => 8.0], null);
        $this->assertSame('LOT 10', $entry->fresh()->batch_number);
    }

    public function test_the_history_and_export_show_a_batch_column_only_when_one_is_used(): void
    {
        $shop = $this->pharmacy();
        $product = $this->medicine($shop, 'Brufen 400mg', 'Ibuprofen 400mg');
        CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 5.0, 'selling_price' => 8.0]], null, 'paid', null, now()->toDateString(), null, null);

        $this->assertNotContains('Batch No.', (new StockInReport)->tableHeaders());

        CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 4, 'cost_price' => 5.0, 'selling_price' => 8.0, 'batch_number' => 'X1']], null, 'paid', null, now()->toDateString(), null, null);

        $report = new StockInReport;
        $headers = $report->tableHeaders();
        $this->assertContains('Batch No.', $headers);
        $this->assertCount(count($headers), $report->columnTypes());
        $this->assertSame('X1', $report->tableRows(false)[0][array_search('Batch No.', $headers)]);

        Livewire::test('stock-ins.history')->assertSee('Batch X1');
    }

    public function test_goods_received_on_a_purchase_order_carry_the_batch_number(): void
    {
        $shop = $this->pharmacy(['purchase_orders_enabled' => true]);
        $product = $this->medicine($shop, 'Brufen 400mg', 'Ibuprofen 400mg');
        $vendor = \App\Models\Vendor::create(['shop_id' => $shop->id, 'name' => 'Muller & Phipps']);
        $order = \App\Actions\SavePurchaseOrder::handle(null, ['vendor_id' => $vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null], [['product_id' => $product->id, 'quantity' => 20, 'expected_cost' => 5.0]], auth()->id());

        \App\Actions\ReceiveGoods::handle($order->fresh(), [['po_line_id' => $order->lines()->sole()->id, 'quantity' => 20, 'cost_price' => 5.0, 'selling_price' => 8.0, 'batch_number' => 'GRN-B1']], now()->toDateString(), null, null, auth()->id());

        $this->assertSame('GRN-B1', StockIn::sole()->batch_number);
    }

    // ── Size and colour ─────────────────────────────────────────────────

    public function test_a_label_gives_its_size_and_colour(): void
    {
        $cases = [
            'M / Red' => ['M', 'Red'],
            '42 / Black' => ['42', 'Black'],
            'XL' => ['XL', null],
            '38' => ['38', null],
            'Navy Blue' => [null, 'Navy Blue'],
            'Off White' => [null, 'Off White'],
            'Ferozi' => [null, 'Ferozi'],
            'Free Size' => ['Free Size', null],
            '' => [null, null],
        ];

        foreach ($cases as $label => [$size, $colour]) {
            $this->assertSame(['size' => $size, 'colour' => $colour], ProductGroupService::optionsFromLabel($label), $label);
        }
    }

    public function test_variants_made_by_the_wizard_know_which_value_is_the_colour(): void
    {
        $this->clothing();

        Livewire::test('products.index')
            ->call('openCreateGroup')
            ->set('groupName', 'Dupatta')->set('groupType', 'clothing')
            // Colours only, including one that isn't on the colour word list.
            ->set('groupColours', 'Red, Tea Pink, Pistachio')
            ->set('groupPrice', '800')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $variants = ProductGroup::sole()->variants()->get();
        $this->assertSame([null, null, null], $variants->pluck('variant_size')->all());
        $this->assertSame(['Red', 'Tea Pink', 'Pistachio'], $variants->pluck('variant_colour')->all());
    }

    public function test_size_and_colour_follow_a_hand_edited_label_and_clear_when_a_variant_leaves(): void
    {
        $shop = $this->clothing();
        $group = $this->group($shop, ['S', 'M'], ['Red']);
        $variant = $group->variants()->first();

        Livewire::test('products.index')->call('openEdit', $variant->id)->set('variantLabel', 'XS / Maroon')->call('save')->assertHasNoErrors();

        $variant->refresh();
        $this->assertSame(['XS', 'Maroon'], [$variant->variant_size, $variant->variant_colour]);

        $service = app(ProductGroupService::class);
        $service->detach($variant);
        $variant->refresh();
        $this->assertSame([null, null, null], [$variant->variant_label, $variant->variant_size, $variant->variant_colour]);

        // Attaching an existing product reads its label.
        $service->attach($group->fresh(), $variant, 'L / Red');
        $variant->refresh();
        $this->assertSame(['L', 'Red'], [$variant->variant_size, $variant->variant_colour]);
    }

    public function test_the_import_takes_option_one_as_size_and_option_two_as_colour(): void
    {
        Storage::fake('local');
        $shop = $this->clothing();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Main Category', 'Selling Price', 'Variant Group', 'Option 1', 'Option 2'],
            ['', 'Clothing', '1500', 'Kurta', 'M', 'Pistachio'],
            ['', 'Clothing', '1500', 'Kurta', '', 'Rust'],
            ['', 'Clothing', '1500', 'Kurta', 'XL', ''],
        ], null, 'A1');
        $path = Storage::disk('local')->path('test-imports/kurta.xlsx');
        @mkdir(dirname($path), 0777, true);
        (new Xlsx($spreadsheet))->save($path);

        app(ProductImportService::class)->commit($path, $shop->id);

        $variants = ProductGroup::sole()->variants()->get();
        $this->assertSame(['M', null, 'XL'], $variants->pluck('variant_size')->all());
        $this->assertSame(['Pistachio', 'Rust', null], $variants->pluck('variant_colour')->all());
    }

    public function test_the_migration_fills_in_size_and_colour_for_existing_variants(): void
    {
        $shop = $this->clothing();
        $group = $this->group($shop, ['S'], ['Red']);
        $plain = Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Belt', 'price' => 300, 'stock_quantity' => 0, 'details' => ['category' => null]]);
        $loneColour = app(ProductGroupService::class)->addVariants($group, ['Black'], 1500, null)[0];

        // As they were before the columns existed.
        DB::table('products')->update(['variant_size' => null, 'variant_colour' => null]);

        $migration = require database_path('migrations/2026_12_06_090000_add_medicine_fields_batch_numbers_and_variant_options.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'variant_size'));
        $migration->up();

        $rows = DB::table('products')->get()->keyBy('id');
        $first = $group->variants()->first();
        $this->assertSame(['S', 'Red'], [$rows[$first->id]->variant_size, $rows[$first->id]->variant_colour]);
        $this->assertSame([null, 'Black'], [$rows[$loneColour->id]->variant_size, $rows[$loneColour->id]->variant_colour]);
        $this->assertSame([null, null], [$rows[$plain->id]->variant_size, $rows[$plain->id]->variant_colour]);
        $this->assertTrue(Schema::hasColumn('stock_ins', 'batch_number'));
    }

    // ── Stock In grid ───────────────────────────────────────────────────

    public function test_the_grid_lays_out_sizes_by_colours_and_adds_a_line_per_filled_cell(): void
    {
        $shop = $this->clothing();
        $group = $this->group($shop, ['S', 'M', 'L'], ['White', 'Navy']);
        $variants = $group->variants()->get()->keyBy('variant_label');

        $page = Livewire::test('stock-ins.create')
            ->set('productId', (string) $variants['M / Navy']->id)
            ->assertSee('Enter every size and colour of Lawn Kurta at once')
            ->call('openGrid')
            ->assertSee('Lawn Kurta: quantity received')
            ->set('gridQuantities.'.$variants['S / White']->id, '4')
            ->set('gridQuantities.'.$variants['M / Navy']->id, '6')
            ->set('gridQuantities.'.$variants['L / White']->id, '0')
            ->set('gridCostPrice', '950')
            ->call('addGrid')
            ->assertHasNoErrors()
            ->assertSet('gridGroupId', null);

        $cart = collect($page->get('cart'));
        $this->assertCount(2, $cart);
        $this->assertEqualsWithDelta(9500.0, $page->instance()->grandTotalCost(), 0.01);
        // A blank selling price keeps each variant's own.
        $this->assertSame([1500.0, 1500.0], $cart->pluck('selling_price')->map(fn ($p) => (float) $p)->all());

        $page->call('save');

        $this->assertSame(4, $variants['S / White']->fresh()->stock_quantity);
        $this->assertSame(6, $variants['M / Navy']->fresh()->stock_quantity);
        $this->assertSame(0, $variants['L / White']->fresh()->stock_quantity);
        $this->assertSame(2, StockIn::count());
        $this->assertSame([950.0, 950.0], StockIn::orderBy('id')->pluck('cost_price')->map(fn ($p) => (float) $p)->all());
    }

    public function test_the_grid_without_a_cost_uses_each_variants_own_and_refuses_an_empty_or_part_piece_grid(): void
    {
        $shop = $this->clothing();
        $group = $this->group($shop, ['38', '40'], []);
        [$small, $large] = $group->variants()->get()->all();
        $large->update(['cost_price' => 1000, 'price' => 1800]);

        $page = Livewire::test('stock-ins.create')->set('productId', (string) $small->id)->call('openGrid');

        $page->call('addGrid')->assertHasErrors('gridQuantities');
        $page->set('gridQuantities.'.$small->id, '1.5')->call('addGrid')->assertHasErrors('gridQuantities.'.$small->id);

        $page->set('gridQuantities.'.$small->id, '2')->set('gridQuantities.'.$large->id, '3')->call('addGrid')->assertHasNoErrors();

        $lines = collect($page->get('cart'))->keyBy('product_id');
        $this->assertSame([900.0, 1500.0], [(float) $lines[$small->id]['cost_price'], (float) $lines[$small->id]['selling_price']]);
        $this->assertSame([1000.0, 1800.0], [(float) $lines[$large->id]['cost_price'], (float) $lines[$large->id]['selling_price']]);
    }

    public function test_the_grid_ignores_another_groups_or_shops_product_ids(): void
    {
        $shop = $this->clothing();
        $group = $this->group($shop, ['S'], ['Red']);
        $outsider = Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Belt', 'price' => 300, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        $page = Livewire::test('stock-ins.create')->set('productId', (string) $group->variants()->first()->id)->call('openGrid')
            ->set('gridQuantities.'.$outsider->id, '5')
            ->call('addGrid')
            ->assertHasErrors('gridQuantities');

        $this->assertSame([], $page->get('cart'));
    }

    public function test_a_plain_product_has_no_grid(): void
    {
        $shop = $this->clothing();
        $plain = Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Belt', 'price' => 300, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        Livewire::test('stock-ins.create')->set('productId', (string) $plain->id)
            ->assertDontSee('Enter every size and colour')
            ->call('openGrid')
            ->assertSet('gridGroupId', null);
    }

    // ── Labels for a receipt ────────────────────────────────────────────

    public function test_saving_a_receipt_offers_labels_with_a_copy_per_piece(): void
    {
        $shop = $this->clothing();
        $group = $this->group($shop, ['S', 'M'], ['Red']);
        [$s, $m] = $group->variants()->get()->all();

        $page = Livewire::test('stock-ins.create')
            ->set('productId', (string) $s->id)->call('openGrid')
            ->set('gridQuantities.'.$s->id, '3')->set('gridQuantities.'.$m->id, '2')
            ->call('addGrid')
            ->call('save')
            ->assertSee('Print labels for this receipt');

        $this->assertSame([$s->id => 3, $m->id => 2], $page->get('receivedLabels'));

        $page->call('printReceivedLabels')->assertHasNoErrors()->assertFileDownloaded();

        // Barcodes were made for the variants that had none.
        $this->assertNotNull($s->fresh()->barcode);
        $this->assertNotNull($m->fresh()->barcode);

        $page->call('dismissReceivedLabels')->assertDontSee('Print labels for this receipt');
    }

    public function test_receipt_labels_respect_the_limit_and_the_barcode_switch(): void
    {
        $shop = $this->clothing();
        $belt = Product::create(['shop_id' => $shop->id, 'type' => 'clothing', 'name' => 'Belt', 'price' => 300, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        $page = Livewire::test('stock-ins.create')
            ->set('productId', (string) $belt->id)->set('quantity', '500')->set('sellingPrice', '300')->call('addLine')->call('save');

        $this->assertSame([$belt->id => 200], $page->get('receivedLabels'));
        $page->set('receivedLabels.'.$belt->id, '0')->call('printReceivedLabels')->assertHasErrors('receivedLabels')->assertNoFileDownloaded();

        $shop->update(['barcode_enabled' => false]);
        $this->actingAs(User::where('shop_id', $shop->id)->first()->fresh());

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $belt->id)->set('quantity', '2')->set('sellingPrice', '300')->call('addLine')->call('save')
            ->assertDontSee('Print labels for this receipt')
            ->call('printReceivedLabels')
            ->assertForbidden();
    }
}
