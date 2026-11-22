<?php

namespace Tests\Feature;

use App\Actions\CreateUdhaarTransaction;
use App\Actions\DeleteShop;
use App\Actions\Payroll;
use App\Actions\ReceiveGoods;
use App\Actions\SavePurchaseOrder;
use App\Actions\StartLedger;
use App\Actions\VendorInvoices;
use App\Enums\AdvanceType;
use App\Ledger\LedgerPoster;
use App\Models\BackupRun;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Services\SubscriptionBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class DeleteShopTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
    }

    protected function backedUp(): void
    {
        BackupRun::create(['status' => BackupRun::STATUS_SUCCESS, 'trigger' => 'schedule', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
    }

    /**
     * A shop with rows behind most of the RESTRICT keys: a subscription
     * invoice (shop), udhaar and party ledger (customer), a PO, GRN and
     * vendor invoice (vendor, purchase order, GRN line, batch), a payroll
     * advance (employee) and a started ledger (ledger accounts), plus files.
     */
    protected function populated(string $name): Shop
    {
        $shop = $this->shop(['name' => $name, 'ledger_enabled' => true, 'purchase_orders_enabled' => true, 'payroll_enabled' => true]);
        // Already started under LEDGER_FORCE_ON.
        $shop = $shop->fresh()->ledger_started_at ? $shop->fresh() : StartLedger::handle($shop);
        $owner = auth()->user();
        $staff = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'avatar_path' => 'avatars/'.$name.'.jpg']);
        Storage::disk('public')->put('avatars/'.$name.'.jpg', 'x');

        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
        CreateUdhaarTransaction::handle($customer, 'given', '500', now()->toDateString());

        $cable = $this->piece($shop, ['image_path' => 'shop-'.$shop->id.'/products/cable.jpg']);
        Storage::disk('public')->put('shop-'.$shop->id.'/products/cable.jpg', 'x');
        $this->batch($cable, 5);
        $this->sell($cable, 2, [], 300);

        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme']);
        $order = SavePurchaseOrder::handle(null, ['vendor_id' => $vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null],
            [['product_id' => $cable->id, 'quantity' => 4, 'expected_cost' => 100]], $owner->id);
        $grn = ReceiveGoods::handle($order->fresh(), [['po_line_id' => $order->lines[0]->id, 'quantity' => 4, 'cost_price' => 100, 'selling_price' => 150]], now()->toDateString(), null, null, $owner->id);
        VendorInvoices::record(['vendor_id' => $vendor->id, 'invoice_number' => 'INV-'.$name, 'invoice_date' => now()->toDateString()],
            [['grn_line_id' => $grn->lines[0]->id, 'billed_quantity' => 4, 'billed_amount' => 400]], [], $owner->id);

        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Ali', 'salary_type' => 'fixed', 'rate' => 30000]);
        Payroll::recordAdvance($employee, AdvanceType::cases()[0], 1000, now()->toDateString(), null, null, $owner->id);

        app(SubscriptionBillingService::class)->create($shop, ['period_start' => now()->toDateString(), 'months' => 1, 'amount' => 2500, 'notes' => null], null);

        Storage::disk('local')->put('support/'.$shop->id.'/1/photo.jpg', 'x');
        Storage::disk('local')->put('payment-proofs/'.$shop->id.'/slip.jpg', 'x');
        DB::table('sessions')->insert(['id' => 'sess-'.$name, 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()]);

        app(LedgerPoster::class)->drain();

        return $shop->fresh();
    }

    /** @return array<string, int> rows per shop table for $shopId */
    protected function rows(int $shopId): array
    {
        return collect(DeleteShop::shopTables())
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->where('shop_id', $shopId)->count()])
            ->all();
    }

    public function test_it_deletes_every_row_and_file_of_the_shop_and_nothing_of_another(): void
    {
        $doomed = $this->populated('Doomed');
        $kept = $this->populated('Kept');
        $doomedUsers = User::where('shop_id', $doomed->id)->pluck('id');
        $keptRows = $this->rows($kept->id);
        $this->backedUp();

        $this->assertGreaterThan(15, count(array_filter($this->rows($doomed->id))), 'the fixture touches many tables');
        $this->assertGreaterThan(0, DB::table('subscription_invoices')->where('shop_id', $doomed->id)->count());
        $this->assertGreaterThan(0, DB::table('ledger_lines')->where('shop_id', $doomed->id)->count());

        $result = DeleteShop::handle($doomed, 'Doomed', $this->admin());

        $this->assertSame([], array_filter($this->rows($doomed->id)));
        $this->assertNull(Shop::find($doomed->id));
        $this->assertSame(0, User::whereIn('id', $doomedUsers)->count(), 'logins are deleted, not left without a shop');
        $this->assertSame(0, DB::table('sessions')->where('id', 'sess-Doomed')->count());
        $this->assertGreaterThan(0, $result['tables']['sales'] ?? 0);
        $this->assertSame(4, $result['files']);

        $this->assertSame($keptRows, $this->rows($kept->id));
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-Kept')->count());

        Storage::disk('public')->assertMissing('shop-'.$doomed->id.'/products/cable.jpg');
        Storage::disk('public')->assertMissing('avatars/Doomed.jpg');
        Storage::disk('local')->assertMissing('support/'.$doomed->id.'/1/photo.jpg');
        Storage::disk('local')->assertMissing('payment-proofs/'.$doomed->id.'/slip.jpg');
        Storage::disk('public')->assertExists('shop-'.$kept->id.'/products/cable.jpg');
        Storage::disk('public')->assertExists('avatars/Kept.jpg');
        Storage::disk('local')->assertExists('support/'.$kept->id.'/1/photo.jpg');
    }

    public function test_children_come_before_the_tables_they_point_at(): void
    {
        $order = array_flip(DeleteShop::ordered(DeleteShop::shopTables()));

        $this->assertLessThan($order['customers'], $order['udhaar_transactions']);
        $this->assertLessThan($order['vendors'], $order['purchase_orders']);
        $this->assertLessThan($order['employees'], $order['employee_advances']);
        $this->assertLessThan($order['users'], $order['sales']);
    }

    public function test_it_is_refused_without_a_recent_successful_backup(): void
    {
        $shop = $this->populated('Doomed');
        $before = $this->rows($shop->id);

        BackupRun::create(['status' => BackupRun::STATUS_SUCCESS, 'trigger' => 'schedule', 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3)]);
        BackupRun::create(['status' => BackupRun::STATUS_FAILED, 'trigger' => 'schedule', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'error' => 'R2 not configured']);

        try {
            DeleteShop::handle($shop, 'Doomed', $this->admin());
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No backup has succeeded', $e->getMessage());
        }

        $this->assertSame($before, $this->rows($shop->id));
    }

    public function test_it_is_refused_when_the_typed_name_does_not_match_or_the_user_is_not_a_super_admin(): void
    {
        $shop = $this->populated('Doomed');
        $owner = auth()->user();
        $this->backedUp();

        foreach ([['doomed', $this->admin()], ['Doomed', $owner]] as [$typed, $who]) {
            try {
                DeleteShop::handle($shop, $typed, $who);
                $this->fail('Expected a refusal.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull(Shop::find($shop->id));
    }

    public function test_the_danger_zone_deletes_from_the_admin_page(): void
    {
        $shop = $this->populated('Doomed');
        $this->backedUp();

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSeeHtml('data-danger-zone')
            ->assertSee('Delete shop permanently')
            ->set('deleteConfirmName', 'Wrong name')
            ->call('deleteShop')
            ->assertNoRedirect()
            ->set('deleteConfirmName', 'Doomed')
            ->call('deleteShop')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNull(Shop::find($shop->id));
    }

    public function test_the_danger_zone_is_switched_off_while_backups_are_stale(): void
    {
        $shop = $this->populated('Doomed');

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSeeHtml('data-delete-blocked')
            ->assertDontSee('Delete shop permanently')
            ->set('deleteConfirmName', 'Doomed')
            ->call('deleteShop');

        $this->assertNotNull(Shop::find($shop->id));
    }
}
