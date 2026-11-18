<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRevision;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\LegacyImport\LegacyShopImporter;
use App\Support\ApiShopContext;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class LegacyShopImportTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Target', 'email' => 'old@example.com']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'email' => 'owner@example.com']);
    }

    /** A small old shop: opening stock, a sale before the stock-in that tops it up, a cancelled sale, an unpaid batch with no product. */
    protected function legacy(array $overrides = []): array
    {
        $at = fn (string $day, string $time = '10:00:00') => "2026-10-{$day} {$time}";
        $tables = [
            'main_categories' => [
                ['id' => 17, 'shop_id' => 22, 'name' => 'no category', 'slug' => 'mobile', 'is_builtin' => 1, 'created_at' => $at('01'), 'updated_at' => $at('01')],
                ['id' => 18, 'shop_id' => 22, 'name' => 'Accessories', 'slug' => 'accessory', 'is_builtin' => 1, 'created_at' => $at('01'), 'updated_at' => $at('01')],
                ['id' => 26, 'shop_id' => 22, 'name' => 'Cables', 'slug' => 'cables', 'is_builtin' => 0, 'created_at' => $at('01'), 'updated_at' => $at('01')],
                ['id' => 36, 'shop_id' => 22, 'name' => 'Ram Desktop', 'slug' => 'ram', 'is_builtin' => 0, 'created_at' => $at('01'), 'updated_at' => $at('01')],
            ],
            'accessory_category_options' => [
                ['id' => 25, 'shop_id' => 22, 'main_category_id' => 26, 'name' => 'HDMI Cables', 'image_path' => null, 'created_at' => $at('01'), 'updated_at' => $at('01')],
            ],
            'brands' => [['id' => 8, 'shop_id' => 22, 'name' => 'Lenovo', 'created_at' => $at('01'), 'updated_at' => $at('01')]],
            'vendors' => [['id' => 1, 'shop_id' => 22, 'name' => 'LBH', 'phone' => null, 'company_name' => 'LBH - Odean', 'address' => null, 'notes' => null, 'created_at' => $at('01'), 'updated_at' => $at('01')]],
            'customers' => [['id' => 4, 'shop_id' => 22, 'name' => 'Suleman', 'phone' => null, 'address' => 'Sterling', 'created_at' => $at('01'), 'updated_at' => $at('01')]],
            'products' => [
                // Ends at 2: opened with 2, sold 1 on the 3rd, 1 more arrived on the 5th.
                ['id' => 75, 'shop_id' => 22, 'type' => 'cables', 'name' => 'HDMI Cable 3M', 'barcode' => null, 'image_path' => null, 'price' => '550.00', 'cost_price' => '330.00', 'stock_quantity' => 2, 'details' => '{"category":"HDMI Cables"}', 'created_at' => $at('01'), 'updated_at' => $at('05', '11:00:00')],
                ['id' => 59, 'shop_id' => 22, 'type' => 'accessory', 'name' => 'Mouse Pad', 'barcode' => null, 'image_path' => null, 'price' => '1200.00', 'cost_price' => '800.00', 'stock_quantity' => 3, 'details' => '{"category":null}', 'created_at' => $at('01'), 'updated_at' => $at('01')],
            ],
            'stock_ins' => [
                ['id' => 40, 'shop_id' => 22, 'product_id' => 75, 'vendor_id' => 1, 'product_name' => 'HDMI Cable 3M', 'user_id' => 23, 'quantity' => 1, 'cost_price' => '330.00', 'selling_price' => '550.00', 'quantity_remaining' => 1, 'total_cost' => '330.00', 'payment_status' => 'paid', 'amount_paid' => '330.00', 'stock_date' => '2026-10-05', 'note' => null, 'status' => 'active', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('21'), 'updated_at' => $at('21')],
                ['id' => 35, 'shop_id' => 22, 'product_id' => null, 'vendor_id' => 1, 'product_name' => 'Dell E7470 Battery', 'user_id' => 23, 'quantity' => 1, 'cost_price' => '5000.00', 'selling_price' => '7000.00', 'quantity_remaining' => 1, 'total_cost' => '5000.00', 'payment_status' => 'unpaid', 'amount_paid' => '0.00', 'stock_date' => '2026-10-13', 'note' => null, 'status' => 'active', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('21'), 'updated_at' => $at('21')],
            ],
            'sales' => [
                ['id' => 293, 'shop_id' => 22, 'user_id' => 23, 'customer_id' => null, 'subtotal' => '650.00', 'discount_amount' => '0.00', 'total' => '650.00', 'status' => 'completed', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('03', '07:48:37'), 'updated_at' => $at('20')],
                ['id' => 32, 'shop_id' => 22, 'user_id' => 23, 'customer_id' => 4, 'subtotal' => '1200.00', 'discount_amount' => '0.00', 'total' => '1200.00', 'status' => 'cancelled', 'cancelled_at' => $at('09'), 'cancelled_by' => 23, 'cancel_reason' => null, 'created_at' => $at('02'), 'updated_at' => $at('09')],
            ],
            'sale_items' => [
                // Sold above list, at a cost that is not the product's: both kept as recorded.
                ['id' => 372, 'shop_id' => 22, 'sale_id' => 293, 'product_id' => 75, 'stock_in_id' => null, 'product_name' => 'HDMI Cable 3M', 'product_type' => 'cables', 'unit_price' => '650.00', 'list_price' => '550.00', 'cost_price' => '350.00', 'quantity' => 1, 'discount_amount' => '0.00', 'line_total' => '650.00'],
                ['id' => 33, 'shop_id' => 22, 'sale_id' => 32, 'product_id' => 59, 'stock_in_id' => null, 'product_name' => 'Mouse Pad', 'product_type' => 'accessory', 'unit_price' => '1200.00', 'list_price' => null, 'cost_price' => null, 'quantity' => 1, 'discount_amount' => '0.00', 'line_total' => '1200.00'],
            ],
            'sale_payments' => [
                ['id' => 280, 'shop_id' => 22, 'sale_id' => 293, 'user_id' => 23, 'amount' => '650.00', 'payment_date' => '2026-10-20', 'created_at' => $at('20'), 'updated_at' => $at('20')],
                ['id' => 18, 'shop_id' => 22, 'sale_id' => 32, 'user_id' => 23, 'amount' => '0.00', 'payment_date' => '2026-10-02', 'created_at' => $at('02'), 'updated_at' => $at('02')],
                ['id' => 19, 'shop_id' => 22, 'sale_id' => 32, 'user_id' => 23, 'amount' => '500.00', 'payment_date' => '2026-10-02', 'created_at' => $at('02'), 'updated_at' => $at('02')],
            ],
            'expenses' => [['id' => 4, 'shop_id' => 22, 'user_id' => 23, 'description' => 'Tea', 'amount' => '680.00', 'expense_date' => '2026-10-01', 'status' => 'active', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('09'), 'updated_at' => $at('09')]],
            'udhaar_transactions' => [
                ['id' => 9, 'shop_id' => 22, 'customer_id' => 4, 'user_id' => 23, 'type' => 'repayment', 'amount' => '500.00', 'transaction_date' => '2026-09-30', 'note' => 'Cash', 'reverses_transaction_id' => null, 'status' => 'active', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('01'), 'updated_at' => $at('01')],
                ['id' => 12, 'shop_id' => 22, 'customer_id' => 4, 'user_id' => 23, 'type' => 'repayment_reversal', 'amount' => '500.00', 'transaction_date' => '2026-10-09', 'note' => 'Correction of transaction #9', 'reverses_transaction_id' => 9, 'status' => 'active', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('09'), 'updated_at' => $at('09')],
            ],
            'repairs' => [['id' => 2, 'shop_id' => 22, 'user_id' => 23, 'customer_id' => 4, 'category' => 'cables', 'sub_category' => null, 'description' => 'Repair', 'amount' => '8000.00', 'service_charges' => null, 'products_charges' => null, 'discount' => '0.00', 'total' => '8000.00', 'payment_status' => 'partial', 'amount_paid' => '6000.00', 'status' => 'active', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'created_at' => $at('06'), 'updated_at' => $at('09')]],
            'sale_revisions' => [['id' => 6, 'shop_id' => 22, 'sale_id' => 32, 'action' => 'cancelled', 'user_id' => 23, 'reason' => null, 'snapshot' => '{"total":1200,"customer_id":4,"items":[{"product_id":59,"quantity":1}]}', 'created_at' => $at('09')]],
            'module_revisions' => [['id' => 39, 'shop_id' => 22, 'revisionable_type' => 'App\\Models\\StockIn', 'revisionable_id' => 40, 'action' => 'edited', 'user_id' => 23, 'reason' => null, 'snapshot' => '{"quantity":1,"vendor_id":1}', 'created_at' => $at('21')]],
        ];

        return array_replace_recursive([
            'format' => LegacyShopImporter::FORMAT,
            'source' => ['database' => 'old', 'shop_id' => 22],
            'shop' => ['id' => 22, 'name' => 'Techlinks', 'email' => 'old@example.com', 'disabled_screens' => '["bills","settings-networks","settings-percentage","sim-sales"]'],
            'users' => [['id' => 23, 'name' => 'Naveed', 'email' => 'old@example.com']],
            'tables' => $tables,
        ], $overrides);
    }

    protected function file(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy').'.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    protected function import(array $data, bool $commit = true, array $options = []): PendingCommand
    {
        return $this->artisan('legacy:import', array_merge([
            'file' => $this->file($data),
            '--shop' => $this->shop->id,
            '--email' => 'old@example.com',
            '--database' => config('database.connections.mysql.database'),
            '--commit' => $commit,
        ], $options));
    }

    protected function inShop(callable $callback): mixed
    {
        return ApiShopContext::current()->run($this->shop->id, $callback);
    }

    public function test_a_dry_run_reports_everything_and_keeps_nothing(): void
    {
        $this->import($this->legacy(), commit: false)
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
        $this->assertSame(0, Sale::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
        $this->assertNull($this->shop->fresh()->disabled_screens);
    }

    public function test_it_replays_stock_and_sales_and_keeps_recorded_values(): void
    {
        Mail::fake();

        $this->import($this->legacy())->expectsOutputToContain('Committed')->assertSuccessful();

        $this->inShop(function () {
            $cable = Product::where('name', 'HDMI Cable 3M')->firstOrFail();
            $this->assertSame('cables', $cable->type);
            $this->assertEquals(2, $cable->stock_quantity);
            $this->assertSame('2026-10-05 11:00:00', $cable->updated_at->format('Y-m-d H:i:s'));

            // The sale on the 3rd came from opening stock, so the batch from the 5th is untouched.
            $batch = StockIn::where('product_id', $cable->id)->firstOrFail();
            $this->assertEquals(1, $batch->quantity_remaining);
            $this->assertSame('2026-10-21 10:00:00', $batch->created_at->format('Y-m-d H:i:s'));
            $this->assertSame(['330.00'], $batch->payments()->pluck('amount')->all());
            $this->assertSame('2026-10-05', $batch->payments()->first()->payment_date->toDateString());

            $sale = Sale::whereHas('items', fn ($q) => $q->where('product_id', $cable->id))->firstOrFail();
            $item = $sale->items()->firstOrFail();
            $this->assertNull($item->stock_in_id);
            $this->assertSame(['650.00', '550.00', '350.00'], [$item->unit_price, $item->list_price, $item->cost_price]);
            $this->assertSame('2026-10-03 07:48:37', $sale->created_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-20', $sale->payments()->first()->payment_date->toDateString());
            $this->assertSame($this->owner->id, $sale->user_id);

            // The cancelled sale is kept with its payments and moved no stock.
            $this->assertEquals(3, Product::where('name', 'Mouse Pad')->value('stock_quantity'));
            $cancelled = Sale::withoutGlobalScopes()->where('shop_id', $this->shop->id)->where('status', 'cancelled')->firstOrFail();
            $this->assertSame(2, $cancelled->payments()->count());

            $orphan = StockIn::whereNull('product_id')->firstOrFail();
            $this->assertSame('Dell E7470 Battery', $orphan->product_name);
            $this->assertSame(0, $orphan->payments()->count());
            $this->assertSame(5000.0, Vendor::firstOrFail()->totalOwed());

            $suleman = Customer::firstOrFail();
            $this->assertSame(0.0, $suleman->udhaarBalance());
            $this->assertSame(2000.0, CustomerDuesStatement::for($suleman)->total());
            $reversal = UdhaarTransaction::where('type', 'repayment_reversal')->firstOrFail();
            $this->assertSame(UdhaarTransaction::where('type', 'repayment')->value('id'), $reversal->reverses_transaction_id);

            $revision = SaleRevision::firstOrFail();
            $this->assertSame($cancelled->id, $revision->sale_id);
            $this->assertSame($suleman->id, $revision->snapshot['customer_id']);
            $this->assertSame(Product::where('name', 'Mouse Pad')->value('id'), $revision->snapshot['items'][0]['product_id']);

            // Built-ins carry the app's names; an unused, mismatched slug is corrected.
            $this->assertSame(['accessory' => 'Accessory', 'cables' => 'Cables', 'mobile' => 'Mobile Phone', 'ram-desktop' => 'Ram Desktop'], MainCategory::orderBy('slug')->pluck('name', 'slug')->all());
        });

        $this->assertSame(['bills', 'wallet-loads', 'balance-loads', 'nadra-verifications', 'sim-sales', 'shop-accounts'], $this->shop->fresh()->disabled_screens);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        $this->artisan('legacy:check', ['--shop' => $this->shop->id, '--against' => $this->file($this->legacy())])->assertSuccessful();
    }

    public function test_it_refuses_a_shop_that_already_has_data_or_another_email(): void
    {
        $this->import($this->legacy())->assertSuccessful();
        $this->import($this->legacy())->expectsOutputToContain('already has 2 products rows')->assertFailed();

        $other = Shop::create(['name' => 'Other', 'email' => 'someone@example.com']);
        User::factory()->create(['shop_id' => $other->id, 'is_owner' => true]);
        $this->import($this->legacy(), options: ['--shop' => $other->id])->expectsOutputToContain('email is not old@example.com')->assertFailed();
    }

    public function test_it_refuses_when_the_database_named_is_not_the_one_connected(): void
    {
        $this->import($this->legacy(), options: ['--database' => 'production_db'])
            ->expectsOutputToContain('Refusing')
            ->assertFailed();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_it_keeps_nothing_when_a_sale_would_draw_from_a_batch_it_did_not_use(): void
    {
        $data = $this->legacy();
        // Stock arriving before the sale means FIFO would now take the batch, unlike the old shop.
        $data['tables']['stock_ins'][0]['stock_date'] = '2026-10-02';

        $this->import($data)->expectsOutputToContain('would draw from different stock')->assertFailed();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
        $this->assertSame(0, StockIn::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }
}
