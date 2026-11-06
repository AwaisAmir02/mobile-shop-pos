<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CancelStockIn;
use App\Actions\ConsumeStockFifo;
use App\Actions\CreateProduct;
use App\Actions\UpdateSale;
use App\Actions\UpdateStockIn;
use App\Enums\QuantityUnit;
use App\Exports\TableExport;
use App\Models\SaleItem;
use App\Models\StockIn;
use App\ReportQueries\ProductReport;
use App\ReportQueries\StockInReport;
use App\Services\ShopReportService;
use App\Support\Quantity;
use App\Support\SaleCartMath;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Sheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

/** The numbers: schema, cast, FIFO, money, Actions and totals. */
class MeasuredQuantitiesTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    // ── Schema ─────────────────────────────────────────────────────────

    public function test_the_quantity_columns_are_unsigned_decimals_and_the_fifo_index_survived(): void
    {
        foreach (['products' => ['stock_quantity'], 'stock_ins' => ['quantity', 'quantity_remaining'], 'sale_items' => ['quantity'], 'whatsapp_order_items' => ['quantity']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('decimal(12,3) unsigned', DB::selectOne("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column])->Type, "{$table}.{$column}");
            }

            $this->assertTrue(Schema::hasColumn($table, 'unit'));
        }

        $this->assertSame(
            ['product_id', 'quantity_remaining', 'stock_date', 'id'],
            collect(DB::select("SHOW INDEX FROM stock_ins WHERE Key_name = 'stock_ins_fifo_lookup'"))->pluck('Column_name')->all(),
        );
    }

    public function test_the_database_itself_refuses_negative_stock(): void
    {
        $product = $this->product($this->shop(), ['stock_quantity' => 1.5]);
        $batch = $this->batch($product, 2);

        $attempts = [
            fn () => DB::table('products')->where('id', $product->id)->update(['stock_quantity' => DB::raw('stock_quantity - 10')]),
            fn () => DB::table('products')->where('id', $product->id)->update(['stock_quantity' => -0.001]),
            fn () => $product->fresh()->decrement('stock_quantity', 10),
            fn () => DB::table('stock_ins')->where('id', $batch->id)->update(['quantity_remaining' => DB::raw('quantity_remaining - 2.001')]),
        ];

        foreach ($attempts as $attempt) {
            DB::beginTransaction();

            try {
                $attempt();
                $this->fail('Negative stock got into the database.');
            } catch (QueryException $e) {
                $this->assertSame('22003', $e->getCode(), $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }

        $this->assertSame(3.5, $product->fresh()->stock_quantity);
    }

    // ── Cast and maths ─────────────────────────────────────────────────

    public function test_whole_values_read_as_plain_integers_and_fractions_as_floats(): void
    {
        $shop = $this->shop();

        $this->assertSame(5, $this->product($shop, ['stock_quantity' => 5])->fresh()->stock_quantity);
        $this->assertSame(5, $this->product($shop, ['name' => 'b', 'stock_quantity' => '5.000'])->fresh()->stock_quantity);
        $this->assertSame(1.5, $this->product($shop, ['name' => 'c', 'stock_quantity' => 1.5])->fresh()->stock_quantity);

        // Direct SQL bypasses the cast, which is why those callers normalize.
        $this->assertSame('5.000', DB::table('products')->where('name', 'b')->value('stock_quantity'));
        $this->assertSame(5, Quantity::normalize('5.000'));
    }

    public function test_quantity_arithmetic_has_no_float_residue(): void
    {
        $this->assertNotSame(0.0, 0.1 + 0.2 - 0.3, 'The trap this guards against.');
        $this->assertSame(0, Quantity::subtract(Quantity::add(0.1, 0.2), 0.3));
        $this->assertSame(1, Quantity::sum([0.1, 0.2, 0.7]));
        $this->assertSame('1.5', Quantity::plain(1.5));
        $this->assertSame('5', Quantity::plain(5));
        $this->assertSame('1,234.5', Quantity::grouped(1234.5));
        $this->assertSame('1,000', Quantity::grouped(1000));
        $this->assertSame(0, Quantity::toThousandths(''));
        $this->assertSame(0, Quantity::toThousandths('abc'));
    }

    public function test_money_rounds_half_up_from_the_exact_amount(): void
    {
        // 33.33 × 1.5 is exactly 49.995; float maths lands just below it.
        $this->assertSame(50.0, Quantity::money(33.33, 1.5));
        $this->assertSame(3.33, Quantity::money(10, 0.333));
        $this->assertSame(0.0, Quantity::money(100, 1, 150));
    }

    #[DataProvider('wholeLines')]
    public function test_whole_quantities_give_the_same_stored_figures_as_before(float $price, int $quantity, float $discount): void
    {
        $old = (float) number_format(max(0.0, $price * $quantity - $discount), 2, '.', '');

        $this->assertSame($old, SaleCartMath::lineTotal(['unit_price' => $price, 'quantity' => $quantity, 'discount' => $discount]));
    }

    public static function wholeLines(): array
    {
        return [[1.1, 3, 0.0], [19.99, 7, 1.5], [0.07, 100, 0.0], [1234.56, 12, 34.56], [100.0, 1, 150.0]];
    }

    // ── FIFO ───────────────────────────────────────────────────────────

    public function test_a_quantity_exactly_covered_by_batches_leaves_no_fallback_line(): void
    {
        $shop = $this->shop();

        // Pairs like these left a false remainder on raw float maths.
        foreach ([[0.1, 0.2], [0.7, 0.1], [1.1, 2.2], [0.3, 0.6], [4.35, 0.15], [2.675, 1.005], [1.001, 0.002], [33.3, 66.6]] as $i => [$first, $second]) {
            $product = $this->product($shop, ['name' => "Pair {$i}"]);
            $this->batch($product, $first);
            $this->batch($product, $second);
            $total = Quantity::add($first, $second);

            $this->assertCount(0, collect(ConsumeStockFifo::preview($product, $total))->whereNull('stock_in_id'), "preview {$first}+{$second}");
            $this->assertCount(0, collect(ConsumeStockFifo::handle($product, $total))->whereNull('stock_in_id'), "handle {$first}+{$second}");
            $this->assertSame(0, StockIn::where('product_id', $product->id)->where('quantity_remaining', '>', 0)->count());
        }
    }

    public function test_a_fractional_sale_draws_its_batches_oldest_first(): void
    {
        $product = $this->product($this->shop());
        $old = $this->batch($product, 1.25, ['stock_date' => now()->subDays(3)->toDateString(), 'cost_price' => 200]);
        $new = $this->batch($product, 5, ['selling_price' => 320, 'cost_price' => 240]);

        $sale = $this->sell($product, 2);
        $items = $sale->items()->orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame($old->id, $items[0]->stock_in_id);
        $this->assertSame(1.25, $items[0]->quantity);
        $this->assertSame(375.0, (float) $items[0]->line_total);
        $this->assertSame(200.0, (float) $items[0]->cost_price);
        $this->assertSame(0.75, $items[1]->quantity);
        $this->assertSame(240.0, (float) $items[1]->line_total);
        $this->assertSame(QuantityUnit::Kg, $items[0]->unit);
        $this->assertSame(615.0, (float) $sale->total);
        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(4.25, $new->fresh()->quantity_remaining);
        $this->assertSame(4.25, $product->fresh()->stock_quantity);
    }

    public function test_the_preview_total_is_what_the_sale_charges(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 0.333, ['selling_price' => 33.33]);
        $this->batch($product, 0.667, ['selling_price' => 66.67]);

        $preview = array_sum(array_column(SaleCartMath::allocateLines(null, 3.33, ConsumeStockFifo::preview($product, 0.9)), 'line_total'));
        $sale = $this->sell($product, 0.9, ['discount' => 3.33]);

        $this->assertSame((float) $sale->total, round($preview, 2));
    }

    public function test_the_last_sub_line_absorbs_the_rounding_difference(): void
    {
        // Each half rounds up to 0.51, so left alone they total a paisa over the whole line.
        $lines = SaleCartMath::allocateLines(1.01, 0.0, [
            ['stock_in_id' => 1, 'quantity' => 0.5, 'cost_price' => null, 'selling_price' => 1.01],
            ['stock_in_id' => 2, 'quantity' => 0.5, 'cost_price' => null, 'selling_price' => 1.01],
        ]);

        $this->assertSame(0.51, $lines[0]['line_total']);
        $this->assertSame(0.5, $lines[1]['line_total']);
    }

    public function test_a_discount_bigger_than_a_sub_line_keeps_its_old_behaviour(): void
    {
        $lines = SaleCartMath::allocateLines(null, 60.0, [
            ['stock_in_id' => 1, 'quantity' => 1, 'cost_price' => null, 'selling_price' => 100],
            ['stock_in_id' => 2, 'quantity' => 1, 'cost_price' => null, 'selling_price' => 10],
        ]);

        $this->assertSame(70.0, $lines[0]['line_total']);
        $this->assertSame(0.0, $lines[1]['line_total']);
    }

    public function test_paid_and_outstanding_stay_exact_on_a_split_fractional_sale(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 1.333, ['selling_price' => 33.33, 'stock_date' => now()->subDay()->toDateString()]);
        $this->batch($product, 2, ['selling_price' => 33.33]);

        // 33.33 × 2.5 is 83.325, so 83.33: paying that leaves no stray paisa.
        $sale = $this->sell($product, 2.5, ['custom_price' => 33.33], paid: 83.33);

        $this->assertSame(83.33, (float) $sale->total);
        $this->assertSame(83.33, round((float) $sale->items()->sum('line_total'), 2));
        $this->assertSame(0.0, $sale->fresh(['payments'])->amountDue());
    }

    public function test_cancelling_restores_each_fraction_to_its_own_batch(): void
    {
        $product = $this->product($this->shop());
        $first = $this->batch($product, 0.1, ['stock_date' => now()->subDay()->toDateString()]);
        $second = $this->batch($product, 0.2);

        $sale = $this->sell($product, 0.3);
        $this->assertSame(0, $product->fresh()->stock_quantity);

        CancelSale::handle($sale, auth()->id());

        $this->assertSame(0.1, $first->fresh()->quantity_remaining);
        $this->assertSame(0.2, $second->fresh()->quantity_remaining);
        $this->assertSame(0.3, $product->fresh()->stock_quantity);
    }

    public function test_an_edit_restores_then_reruns_fifo_and_keeps_a_decimal_snapshot(): void
    {
        $product = $this->product($this->shop());
        $batch = $this->batch($product, 5);
        $sale = $this->sell($product, 1.5, paid: 675);

        $this->assertSame(2.25, ConsumeStockFifo::previewForEdit($product->fresh(), 2.25, [$batch->id => 1.5])[0]['quantity']);

        UpdateSale::handle($sale, [[
            'product_id' => $product->id, 'product_name' => $product->name, 'product_type' => $product->type,
            'quantity' => '2.25', 'discount' => 0.0, 'custom_price' => null,
        ]], 0.0, null, auth()->id(), now()->toDateString());

        $this->assertSame(2.75, $batch->fresh()->quantity_remaining);
        $this->assertSame(2.75, $product->fresh()->stock_quantity);
        $this->assertSame(2.25, $sale->fresh()->items->first()->quantity);

        $snapshot = json_decode(DB::table('sale_revisions')->where('sale_id', $sale->id)->latest('id')->value('snapshot'), true);
        $this->assertSame(1.5, $snapshot['items'][0]['quantity']);
        $this->assertSame('kg', $snapshot['items'][0]['unit']);
    }

    // ── Stock In actions ───────────────────────────────────────────────

    public function test_editing_a_stock_in_moves_stock_by_exactly_the_fraction_and_an_unchanged_one_by_nothing(): void
    {
        $product = $this->product($this->shop());
        $entry = $this->batch($product, 1.1);
        $fields = ['vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 33.33, 'selling_price' => 40.0];

        UpdateStockIn::handle($entry, ['quantity' => '1.100'] + $fields, auth()->id());
        $this->assertSame(1.1, $product->fresh()->stock_quantity);

        UpdateStockIn::handle($entry->fresh(), ['quantity' => 1.6] + $fields, auth()->id());
        $this->assertSame(1.6, $product->fresh()->stock_quantity);
        $this->assertSame(1.6, $entry->fresh()->quantity_remaining);
        $this->assertSame(53.33, (float) $entry->fresh()->total_cost);

        UpdateStockIn::handle($entry->fresh(), ['quantity' => 0.5] + $fields, auth()->id());
        $this->assertSame(0.5, $product->fresh()->stock_quantity);
        $this->assertSame(0.5, $entry->fresh()->quantity_remaining);
    }

    public function test_a_stock_in_cannot_go_below_what_was_sold(): void
    {
        $product = $this->product($this->shop());
        $entry = $this->batch($product, 2);
        $this->sell($product, 1.5);
        $fields = ['vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 250.0, 'selling_price' => 300.0];

        $this->assertRefused(fn () => UpdateStockIn::handle($entry->fresh(), ['quantity' => 1.25] + $fields, auth()->id()), '1.5 kg from this entry have already been sold');
        $this->assertRefused(fn () => CancelStockIn::handle($entry->fresh(), auth()->id()), '1.5 of the 2 kg added');
        $this->assertSame(0.5, $entry->fresh()->quantity_remaining);
    }

    public function test_cancelling_a_fractional_stock_in_takes_back_exactly_its_quantity(): void
    {
        $product = $this->product($this->shop());
        $entry = $this->batch($product, 2.125);
        $this->batch($product, 1);

        CancelStockIn::handle($entry, auth()->id());

        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    // ── The rule holds in the Actions, not only the screens ────────────

    public function test_a_crafted_fraction_for_a_piece_product_is_refused_by_every_action(): void
    {
        $product = $this->piece($this->shop());
        $entry = $this->batch($product, 5);

        $this->assertRefused(fn () => $this->sell($product, 1.5), 'whole number');
        $this->assertSame(5, $product->fresh()->stock_quantity);

        $sale = $this->sell($product, 1);

        $this->assertRefused(fn () => UpdateSale::handle($sale, [[
            'product_id' => $product->id, 'product_name' => $product->name, 'product_type' => $product->type,
            'quantity' => 1.5, 'discount' => 0.0, 'custom_price' => null,
        ]], 0.0, null, auth()->id(), now()->toDateString()), 'whole number');

        $this->assertRefused(fn () => UpdateStockIn::handle($entry->fresh(), [
            'quantity' => 5.5, 'vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => null, 'selling_price' => 1.0,
        ], auth()->id()), 'whole number');

        $this->assertRefused(fn () => CreateProduct::handle(['type' => 'grocery', 'name' => 'x', 'price' => 1, 'stock_quantity' => 1.5, 'details' => []]), 'whole number');
    }

    #[DataProvider('badQuantities')]
    public function test_the_actions_refuse_bad_quantities(mixed $quantity): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 5);

        $this->assertRefused(fn () => $this->sell($product, $quantity));
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public static function badQuantities(): array
    {
        return ['zero' => [0], 'negative' => [-1.5], 'a fourth decimal' => [1.0005], 'text' => ['abc'], 'blank' => ['']];
    }

    #[DataProvider('ruleCases')]
    public function test_validation_rules_follow_the_unit(QuantityUnit $unit, string $value, bool $passes): void
    {
        $this->assertSame($passes, validator(['q' => $value], ['q' => $unit->quantityRules()])->passes());
    }

    public static function ruleCases(): array
    {
        return [
            [QuantityUnit::Piece, '2', true], [QuantityUnit::Piece, '1.5', false], [QuantityUnit::Piece, '0', false],
            [QuantityUnit::Kg, '1.5', true], [QuantityUnit::Kg, '0.001', true], [QuantityUnit::Kg, '0.0001', false],
            [QuantityUnit::Kg, '1.2345', false], [QuantityUnit::Kg, '0', false], [QuantityUnit::Kg, '-1', false],
            [QuantityUnit::Kg, '1e3', false], [QuantityUnit::Gaz, '2.75', true], [QuantityUnit::Metre, '1000000000', false],
        ];
    }

    public function test_the_unit_list_is_the_approved_one(): void
    {
        $this->assertSame(['piece', 'kg', 'litre', 'metre', 'yard', 'gaz'], array_column(QuantityUnit::cases(), 'value'));
    }

    // ── Totals ─────────────────────────────────────────────────────────

    public function test_totals_count_pieces_only_and_show_the_rest_per_unit(): void
    {
        $shop = $this->shop();
        $rice = $this->product($shop);
        $oil = $this->product($shop, ['name' => 'Oil', 'unit' => 'litre']);
        $cable = $this->piece($shop, ['type' => 'accessory']);
        $this->batch($rice, 10.5);
        $this->batch($oil, 4);
        $this->batch($cable, 7);

        $this->sell($rice, 1.5);
        $this->sell($oil, 2.25);
        $this->sell($cable, 3);

        $summary = app(ShopReportService::class)->summary($shop, now()->startOfDay(), now()->endOfDay());

        $this->assertSame(3, $summary['totalItemsSold']);
        $this->assertSame(['kg' => 1.5, 'litre' => 2.25], $summary['soldByUnit']);
        $this->assertSame(7, $summary['totalStockInUnits']);
        $this->assertSame(['kg' => 10.5, 'litre' => 4], $summary['stockInByUnit']);
        $this->assertSame('10.5 kg · 4 litre', QuantityUnit::summarize($summary['stockInByUnit']));

        $report = new StockInReport;
        $this->assertSame(7, $report->totalUnits());
        $this->assertSame(['kg' => 10.5, 'litre' => 4], $report->measuredTotals());

        $cards = collect($report->summaryCards())->pluck('value', 'label');
        $this->assertSame('7', $cards['Total Units']);
        $this->assertSame('10.5 kg · 4 litre', $cards['Weight, Volume & Length']);
    }

    public function test_a_piece_only_shop_sees_exactly_the_old_totals_and_columns(): void
    {
        $this->batch($this->piece($this->shop()), 7);

        $report = new StockInReport;

        $this->assertSame([], $report->measuredTotals());
        $this->assertSame(['Total Units', 'Total Owed to Suppliers'], collect($report->summaryCards())->pluck('label')->all());
        $this->assertNotContains('Unit', $report->tableHeaders());
        $this->assertNotContains('Unit', (new ProductReport)->tableHeaders());
    }

    public function test_the_product_report_values_fractions_exactly(): void
    {
        $product = $this->product($this->shop(), ['stock_quantity' => 0.5, 'price' => 100, 'cost_price' => 80]);
        $this->batch($product, 1.5, ['selling_price' => 200, 'cost_price' => 150]);

        $report = new ProductReport;

        // 1.5 from the batch, plus the 0.5 no batch covers at the product's own price.
        $this->assertSame(350.0, $report->totalStockValue());
        $this->assertSame(265.0, $report->totalCostValue());

        $headers = $report->tableHeaders();
        $excel = array_combine($headers, $report->tableRows(forExcel: true)[0]);

        $this->assertSame(2, $excel['Stock']);
        $this->assertSame('kg', $excel['Unit']);
        $this->assertContains('quantity', $report->columnTypes());
    }

    public function test_stock_in_exports_keep_their_fractions(): void
    {
        $this->batch($this->product($this->shop()), 1.5);

        $report = new StockInReport;
        $headers = $report->tableHeaders();
        $excel = array_combine($headers, $report->tableRows(forExcel: true)[0]);
        $screen = array_combine($headers, $report->tableRows(forExcel: false)[0]);

        $this->assertSame(1.5, $excel['Quantity']);
        $this->assertSame('kg', $excel['Unit']);
        $this->assertSame('1.5', $screen['Remaining']);
        $this->assertCount(count($headers), $report->columnTypes());
    }

    public function test_excel_quantity_cells_keep_decimals_and_whole_numbers_carry_no_stray_point(): void
    {
        $export = new TableExport('T', 'S', 'F', ['Name', 'Qty'], [['A', 1.5], ['B', 5], ['C', 0.125]], ['string', 'quantity']);
        $sheet = (new Spreadsheet)->getActiveSheet();

        $export->registerEvents()[AfterSheet::class](new AfterSheet(new Sheet($sheet), $export));

        $this->assertSame('#,##0.###', $sheet->getStyle('B7')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0', $sheet->getStyle('B8')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0.###', $sheet->getStyle('B9')->getNumberFormat()->getFormatCode());
    }

    // ── Old records outlive the product ────────────────────────────────

    public function test_old_lines_keep_their_own_unit_after_the_product_is_gone(): void
    {
        $product = $this->product($this->shop());
        $entry = $this->batch($product, 5);
        $sale = $this->sell($product, 1.5);

        DB::table('stock_ins')->where('id', $entry->id)->update(['product_id' => null]);
        SaleItem::where('sale_id', $sale->id)->update(['product_id' => null]);
        $product->delete();

        $this->assertSame('5 kg', $entry->fresh()->unit->amount($entry->fresh()->quantity));
        $item = $sale->fresh()->items->first();
        $this->assertSame('1.5 kg', $item->unit->amount($item->quantity));
    }
}
