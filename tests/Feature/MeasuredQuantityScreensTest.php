<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\QuantityUnit;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\ProductGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

/** What people touch: the sale, stock-in, order and product screens. */
class MeasuredQuantityScreensTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    // ── Sale screen ────────────────────────────────────────────────────

    public function test_picking_a_weighed_item_asks_how_much_instead_of_adding_one(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 5);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSet('cart', [])
            ->assertSet('quantityPromptId', $product->id)
            ->assertDispatched('open-modal', name: 'sale-quantity-prompt')
            ->assertSee('5 kg available')
            ->set('quantityPromptValue', '1.5')
            ->call('confirmQuantityPrompt')
            ->assertHasNoErrors()
            ->assertSet('quantityPromptId', null)
            ->assertSet("cart.{$product->id}.quantity", 1.5)
            ->assertSet("cart.{$product->id}.unit", 'kg');
    }

    public function test_scanning_a_weighed_item_asks_too_and_a_piece_still_adds_one(): void
    {
        $shop = $this->shop(['barcode_enabled' => true]);
        $rice = $this->product($shop, ['barcode' => 'RICE1']);
        $this->batch($rice, 5);
        $cable = $this->piece($shop, ['barcode' => 'CABLE1']);
        $this->batch($cable, 5);

        Livewire::test('sales.create')
            ->call('scanBarcode', 'RICE1')
            ->assertSet('cart', [])
            ->assertSet('quantityPromptId', $rice->id)
            ->call('closeQuantityPrompt')
            ->call('scanBarcode', 'CABLE1')
            ->assertSet("cart.{$cable->id}.quantity", 1);
    }

    public function test_half_a_kilo_in_stock_is_not_out_of_stock(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 0.5);

        $this->assertFalse($product->fresh()->isOutOfStock());
        $this->assertTrue($product->fresh()->isLowStock());

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSet('quantityPromptId', $product->id)
            ->set('quantityPromptValue', '0.75')
            ->call('confirmQuantityPrompt')
            ->assertHasErrors('quantityPromptValue')
            ->set('quantityPromptValue', '0.5')
            ->call('confirmQuantityPrompt')
            ->assertHasNoErrors()
            ->call('completeSale')
            ->assertHasNoErrors();

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_the_prompt_refuses_a_fourth_decimal_and_the_cart_keeps_a_piece_whole(): void
    {
        $shop = $this->shop();
        $rice = $this->product($shop);
        $this->batch($rice, 5);
        $cable = $this->piece($shop);
        $this->batch($cable, 5);

        Livewire::test('sales.create')
            ->call('addToCart', $rice->id)
            ->set('quantityPromptValue', '1.2345')
            ->call('confirmQuantityPrompt')
            ->assertHasErrors('quantityPromptValue')
            ->call('closeQuantityPrompt')
            ->call('addToCart', $cable->id)
            ->set("cart.{$cable->id}.quantity", '1.5')
            ->call('completeSale')
            ->assertHasErrors("cart.{$cable->id}.quantity");

        $this->assertSame(5, $cable->fresh()->stock_quantity);
    }

    public function test_a_tampered_cart_unit_cannot_sell_a_fraction_of_a_piece(): void
    {
        $cable = $this->piece($this->shop());
        $this->batch($cable, 5);

        Livewire::test('sales.create')
            ->call('addToCart', $cable->id)
            ->set("cart.{$cable->id}.unit", 'kg')
            ->set("cart.{$cable->id}.quantity", '1.5')
            ->call('completeSale')
            ->assertHasErrors("cart.{$cable->id}.quantity");

        $this->assertSame(5, $cable->fresh()->stock_quantity);
    }

    public function test_the_sale_screen_sale_page_and_invoice_all_show_the_unit(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $this->batch($product, 5);

        Livewire::test('sales.create')->set('search', 'Basmati')->assertSee('5 kg in stock')->assertSee('/ kg');

        $sale = $this->sell($product, 1.5);

        Livewire::test('sales.show', ['sale' => $sale])->assertSee('1.5 kg × Rs 300.00');

        $this->assertStringContainsString('1.5 kg', view('pdf.invoice', ['sale' => $sale->fresh(['items', 'shop', 'user', 'payments'])])->render());
    }

    public function test_sale_edit_asks_how_much_and_allows_what_the_sale_already_holds(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 2);
        $sale = $this->sell($product, 1.5, paid: 600);

        Livewire::test('sales.edit', ['sale' => $sale])
            ->assertSet("cart.{$product->id}.quantity", 1.5)
            ->assertSet("cart.{$product->id}.max_stock", 2)
            ->call('addToCart', $product->id)
            ->assertSet('quantityPromptId', $product->id)
            ->set('quantityPromptValue', '0.5')
            ->call('confirmQuantityPrompt')
            ->assertHasNoErrors()
            ->assertSet("cart.{$product->id}.quantity", 2)
            ->call('saveChanges')
            ->assertHasNoErrors();

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_a_deleted_products_line_keeps_its_fraction_and_unit_through_an_edit(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 5);
        $sale = $this->sell($product, 1.5, paid: 450);

        StockIn::where('product_id', $product->id)->update(['product_id' => null]);
        SaleItem::where('sale_id', $sale->id)->update(['stock_in_id' => null, 'product_id' => null]);
        $product->delete();

        Livewire::test('sales.edit', ['sale' => $sale->fresh()])
            ->assertSee('1.5 kg')
            ->call('saveChanges')
            ->assertHasNoErrors();

        $item = $sale->fresh()->items->first();
        $this->assertSame(1.5, $item->quantity);
        $this->assertSame(QuantityUnit::Kg, $item->unit);
        $this->assertSame(450.0, (float) $sale->fresh()->total);
    }

    // ── Stock In ───────────────────────────────────────────────────────

    public function test_stock_in_takes_decimals_for_a_weighed_product_and_whole_numbers_for_a_piece(): void
    {
        $shop = $this->shop();
        $rice = $this->product($shop);
        $cable = $this->piece($shop);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $cable->id)
            ->set('quantity', '1.5')
            ->set('sellingPrice', '10')
            ->call('addLine')
            ->assertHasErrors('quantity')
            ->set('productId', (string) $rice->id)
            ->assertSee('Quantity (kg)')
            ->set('quantity', '12.125')
            ->set('costPrice', '250')
            ->set('sellingPrice', '300')
            ->call('addLine')
            ->assertHasNoErrors()
            ->call('save');

        $entry = StockIn::where('product_id', $rice->id)->first();

        $this->assertSame(12.125, $entry->quantity);
        $this->assertSame(12.125, $entry->quantity_remaining);
        $this->assertSame(QuantityUnit::Kg, $entry->unit);
        $this->assertSame(3031.25, (float) $entry->total_cost);
        $this->assertSame(12.125, $rice->fresh()->stock_quantity);
    }

    public function test_a_crafted_stock_in_cart_cannot_add_a_fraction_to_a_piece_product(): void
    {
        $cable = $this->piece($this->shop());

        Livewire::test('stock-ins.create')
            ->set('cart', [$cable->id => ['product_id' => $cable->id, 'name' => 'Cable', 'category' => 'x', 'quantity' => 1.5, 'unit' => 'kg', 'cost_price' => null, 'selling_price' => 1.0]])
            ->call('save')
            ->assertHasErrors('quantity');

        $this->assertSame(0, $cable->fresh()->stock_quantity);
        $this->assertSame(0, StockIn::where('product_id', $cable->id)->count());
    }

    public function test_the_stock_in_history_edit_follows_the_entry_unit(): void
    {
        $shop = $this->shop();
        $rice = $this->product($shop);
        $entry = $this->batch($rice, 2);

        Livewire::test('stock-ins.history')
            ->assertSee('+2 kg')
            ->call('openEdit', $entry->id)
            ->assertSet('editUnit', 'kg')
            ->set('editQuantity', '2.5')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame(2.5, $rice->fresh()->stock_quantity);

        $pieces = $this->batch($this->piece($shop), 2);

        Livewire::test('stock-ins.history')
            ->call('openEdit', $pieces->id)
            ->set('editQuantity', '2.5')
            ->call('saveEdit')
            ->assertHasErrors('editQuantity');
    }

    // ── WhatsApp order accept ──────────────────────────────────────────

    public function test_accepting_an_order_allows_decimals_for_a_weighed_line_and_whole_numbers_for_a_piece(): void
    {
        $shop = $this->shop(['whatsapp_ordering_enabled' => true]);
        $rice = $this->product($shop);
        $this->batch($rice, 5);
        $cable = $this->piece($shop, ['price' => 10]);
        $this->batch($cable, 5, ['selling_price' => 10]);

        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923001234567']);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'quoted_total' => 0]);
        $riceLine = $order->items()->create(['shop_id' => $shop->id, 'product_id' => $rice->id, 'product_name' => $rice->name, 'quantity' => 1, 'unit' => 'kg', 'quoted_unit_price' => 300]);
        $cableLine = $order->items()->create(['shop_id' => $shop->id, 'product_id' => $cable->id, 'product_name' => $cable->name, 'quantity' => 1, 'quoted_unit_price' => 10]);

        $page = Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->set("lines.{$riceLine->id}.quantity", '1.5')
            ->set("lines.{$cableLine->id}.quantity", '1.5')
            ->call('accept')
            ->assertHasErrors("lines.{$cableLine->id}.quantity")
            ->assertHasNoErrors("lines.{$riceLine->id}.quantity");

        $this->assertSame(465.0, $page->instance()->subtotal());

        $page->set("lines.{$cableLine->id}.quantity", '2')
            ->call('accept')
            ->assertHasNoErrors();

        $this->assertSame(3.5, $rice->fresh()->stock_quantity);
        $this->assertSame(3, $cable->fresh()->stock_quantity);
        $this->assertSame(QuantityUnit::Kg, SaleItem::where('product_id', $rice->id)->first()->unit);
    }

    // ── Product form, unit lock, feature switch ────────────────────────

    public function test_the_product_form_saves_a_unit_and_validates_stock_by_it(): void
    {
        $this->shop();

        Livewire::test('products.index')
            ->set('type', 'grocery')
            ->set('name', 'Sugar')
            ->set('price', '150')
            ->set('stock_quantity', '2.5')
            ->call('save')
            ->assertHasErrors('stock_quantity')
            ->set('unit', 'kg')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Sugar')->first();
        $this->assertSame(QuantityUnit::Kg, $product->unit);
        $this->assertSame(2.5, $product->stock_quantity);
    }

    public function test_the_quick_create_form_saves_a_unit_too(): void
    {
        $this->shop();

        Livewire::test('products.quick-create')
            ->set('type', 'grocery')
            ->set('name', 'Sugar')
            ->set('price', '150')
            ->set('unit', 'kg')
            ->set('stock_quantity', '2.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(QuantityUnit::Kg, Product::where('name', 'Sugar')->first()->unit);
    }

    public function test_with_the_feature_off_the_form_ignores_a_crafted_unit(): void
    {
        $this->shop(['measured_units_enabled' => false]);

        Livewire::test('products.index')
            ->assertDontSee('Sold By')
            ->set('type', 'grocery')
            ->set('name', 'Sugar')
            ->set('price', '150')
            ->set('unit', 'kg')
            ->set('stock_quantity', '2.5')
            ->call('save')
            ->assertHasErrors('stock_quantity')
            ->set('stock_quantity', '2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(QuantityUnit::Piece, Product::where('name', 'Sugar')->first()->unit);
    }

    public function test_with_the_feature_off_existing_weighed_products_keep_selling(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $this->batch($product, 5);
        $shop->update(['measured_units_enabled' => false]);

        $this->assertSame(450.0, (float) $this->sell($product, 1.5)->total);
    }

    public function test_a_unit_locks_once_there_are_batches_or_sales(): void
    {
        $shop = $this->shop();

        $withBatch = $this->product($shop);
        $this->assertFalse($withBatch->unitIsLocked());
        $this->batch($withBatch, 1);
        $this->assertTrue($withBatch->fresh()->unitIsLocked());
        $this->assertRefused(fn () => $withBatch->fresh()->update(['unit' => 'litre']), "can't be changed");

        $withSale = $this->product($shop, ['name' => 'Loose', 'stock_quantity' => 5]);
        $this->sell($withSale, 1.5);
        $this->assertTrue($withSale->fresh()->unitIsLocked());

        Livewire::test('products.index')
            ->call('openEdit', $withBatch->id)
            ->assertSet('unitLocked', true)
            ->assertSee('Locked because')
            ->set('unit', 'litre')
            ->call('save');

        $this->assertSame(QuantityUnit::Kg, $withBatch->fresh()->unit);
    }

    public function test_a_free_product_can_change_unit_but_not_to_pieces_with_part_unit_stock(): void
    {
        $product = $this->product($this->shop(), ['stock_quantity' => 2]);

        $product->update(['unit' => 'litre']);
        $this->assertSame(QuantityUnit::Litre, $product->fresh()->unit);

        $product->update(['stock_quantity' => 1.5]);
        $this->assertRefused(fn () => $product->fresh()->update(['unit' => 'piece']), 'whole number');
    }

    public function test_mobiles_and_imei_items_only_sell_by_the_piece(): void
    {
        $shop = $this->shop();

        $this->assertRefused(fn () => $this->product($shop, ['type' => 'mobile']), 'by the piece');
        $this->assertRefused(fn () => $this->product($shop, ['details' => ['imei' => '123']]), 'by the piece');
    }

    public function test_the_feature_is_free_off_by_default_and_out_of_the_plan_editor(): void
    {
        $this->assertFalse(Shop::create(['name' => 'New'])->fresh()->measured_units_enabled);
        $this->assertFalse(FeatureKey::MeasuredUnits->planControlled());
        $this->assertNotContains(FeatureKey::MeasuredUnits, FeatureKey::planCases());
    }

    // ── Variants share one unit ────────────────────────────────────────

    public function test_a_group_keeps_one_unit_across_generating_attaching_and_editing(): void
    {
        $shop = $this->shop(['variants_enabled' => true]);
        $service = app(ProductGroupService::class);

        $group = $service->createGroup($shop->id, 'Lawn Cloth', 'grocery', '', null, QuantityUnit::Metre);
        $variants = $service->addVariants($group, ['Red', 'Blue'], 400, null);

        $this->assertSame(QuantityUnit::Metre, $variants[0]->fresh()->unit);

        $odd = $this->product($shop, ['name' => 'Odd One']);
        $this->assertRefused(fn () => $service->attach($group, $odd, 'Odd'), 'same unit');
        $this->assertRefused(fn () => $variants[0]->fresh()->update(['unit' => 'yard']), 'same unit');

        $service->updateGroup($group->fresh(), 'Lawn Cloth', 'grocery', '', QuantityUnit::Gaz);
        $this->assertSame(QuantityUnit::Gaz, $variants[1]->fresh()->unit);

        $this->batch($variants[0]->fresh(), 1.5);
        $this->assertRefused(fn () => $service->updateGroup($group->fresh(), 'Lawn Cloth', 'grocery', '', QuantityUnit::Metre), "can't be changed");
    }

    public function test_the_group_form_creates_a_measured_group(): void
    {
        $this->shop(['variants_enabled' => true]);

        Livewire::test('products.index')
            ->call('openCreateGroup')
            ->set('groupName', 'Lawn Cloth')
            ->set('groupType', 'grocery')
            ->set('groupUnit', 'metre')
            ->set('groupSizes', 'Red, Blue')
            ->set('groupPrice', '400')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $this->assertSame([QuantityUnit::Metre, QuantityUnit::Metre], Product::orderBy('id')->get()->pluck('unit')->all());
    }

    public function test_the_sale_picker_shows_the_group_unit(): void
    {
        $shop = $this->shop(['variants_enabled' => true]);
        $service = app(ProductGroupService::class);
        $group = $service->createGroup($shop->id, 'Lawn Cloth', 'grocery', '', null, QuantityUnit::Metre);
        [$red, $blue] = $service->addVariants($group, ['Red', 'Blue'], 400, null);
        $this->batch($red->fresh(), 2.5);
        $this->batch($blue->fresh(), 1);

        Livewire::test('sales.create')
            ->set('search', 'Lawn')
            ->assertSee('3.5 metre in stock')
            ->call('openVariantPicker', $group->id)
            ->assertSee('2.5 metre in stock')
            ->call('addToCart', $red->id)
            ->assertSet('quantityPromptId', $red->id);
    }

    // ── WhatsApp listing, barcodes, other screens ──────────────────────

    public function test_a_measured_product_can_never_be_listed_on_whatsapp(): void
    {
        $shop = $this->shop(['whatsapp_ordering_enabled' => true]);
        $product = $this->product($shop, ['whatsapp_listed' => true]);

        $this->assertFalse($product->fresh()->whatsapp_listed);

        Livewire::test('products.index')->call('toggleWhatsAppListing', $product->id);

        $this->assertFalse($product->fresh()->whatsapp_listed);
    }

    public function test_a_scale_range_barcode_still_matches_exactly_first(): void
    {
        $product = $this->piece($this->shop(['barcode_enabled' => true]), ['barcode' => '2012345678903']);
        $this->batch($product, 5);

        Livewire::test('sales.create')
            ->call('scanBarcode', '2012345678903')
            ->assertSet("cart.{$product->id}.quantity", 1);
    }

    public function test_the_dashboard_vendor_and_product_screens_show_measured_figures(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $this->batch($product, 3.5);
        $this->sell($product, 1.5);

        Livewire::test('dashboard.index')->assertSee('1.5 kg');
        Livewire::test('products.index')->assertSee('Low · 2 kg')->assertSee('/ kg');
    }
}
