<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\AccessoryCategoryOption;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Billing\Entitlements;
use App\Services\Marketing\PostComposer;
use App\Services\ProductImportService;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class UsageLimitsTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function planWith(array $limits = [], array $features = []): Plan
    {
        return Plan::create([
            'name' => 'Test plan '.uniqid(),
            'price' => 1000,
            'billing_interval' => 'monthly',
            'features' => [['label' => 'x', 'included' => true]],
            'limits' => $limits,
            'feature_flags' => $features,
        ]);
    }

    protected function shopOn(?Plan $plan, array $attributes = []): array
    {
        $shop = Shop::create(['name' => 'Shop '.uniqid(), 'plan_id' => $plan?->id] + $attributes);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        return [$shop, $owner];
    }

    protected function product(Shop $shop, string $name, int $stock = 0): Product
    {
        return Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => $name, 'price' => 100, 'stock_quantity' => $stock, 'details' => ['category' => 'Cable']]);
    }

    protected function quickCreate(string $name)
    {
        return Livewire::test('products.quick-create')->set('type', 'accessory')->set('name', $name)->set('category', 'Cable')->set('price', '100')->call('save');
    }

    public function test_shops_without_a_plan_stay_unlimited_and_keep_their_switches(): void
    {
        [$shop, $owner] = $this->shopOn(null, ['products_allowed' => 1, 'ads_enabled' => true]);
        $this->product($shop, 'Old one');
        $this->actingAs($owner);
        AccessoryCategoryOption::ensureDefaultsExist();

        $this->quickCreate('Second')->assertHasNoErrors();
        $this->quickCreate('Third')->assertHasNoErrors();

        $this->assertSame(3, Product::count());
        app(Entitlements::class)->syncFeatureFlags($shop);
        $this->assertTrue($shop->fresh()->ads_enabled);
        $this->assertSame([], app(Entitlements::class)->meters($shop));
        $this->assertNull(app(Entitlements::class)->limit($shop, LimitKey::Products));
    }

    public function test_the_product_limit_blocks_new_products_but_not_edits_and_archiving_frees_a_slot(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['products' => 2]));
        $sold = $this->product($shop, 'Sold out case');
        $this->product($shop, 'Charger', 5);
        $this->actingAs($owner);
        AccessoryCategoryOption::ensureDefaultsExist();

        $this->quickCreate('Third')->assertHasNoErrors();
        $this->assertSame(2, Product::count());

        Livewire::test('products.index')->call('archive', Product::where('name', 'Charger')->value('id'));
        $this->assertNull(Product::where('name', 'Charger')->value('archived_at'));

        Livewire::test('products.index')->call('archive', $sold->id);
        $this->assertTrue($sold->fresh()->isArchived());
        $this->assertSame(1, app(Entitlements::class)->usage($shop, LimitKey::Products));

        $this->quickCreate('Third')->assertHasNoErrors();
        $this->assertSame(3, Product::count());

        Livewire::test('products.index')->call('restore', $sold->id);
        $this->assertTrue($sold->fresh()->isArchived());

        $sold->fresh()->increment('stock_quantity', 4);
        $this->assertFalse($sold->fresh()->isArchived());

        $this->get(route('products.index'))->assertOk();
    }

    public function test_an_archived_product_returns_when_a_cancelled_sale_puts_stock_back(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['products' => 10]));
        $this->actingAs($owner);
        $product = $this->product($shop, 'Cable', 1);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 1, 'cost_price' => 50, 'selling_price' => 100, 'quantity_remaining' => 1, 'total_cost' => 50, 'payment_status' => 'paid', 'amount_paid' => 50, 'stock_date' => '2026-01-01']);
        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0, null, $owner->id, now()->toDateString(), 100, $shop->id);

        $product->refresh()->update(['archived_at' => now()]);
        \App\Actions\CancelSale::handle($sale, $owner->id, 'Returned');

        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertFalse($product->fresh()->isArchived());
    }

    public function test_a_large_import_brings_in_what_fits_and_reports_the_rest(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['products' => 3]));
        $this->actingAs($owner);
        $this->product($shop, 'Already here');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Name', 'Main Category', 'Selling Price'], ['Gadget A', 'Gadgets', 100], ['Gadget B', 'Gadgets', 100], ['Gadget C', 'Gadgets', 100], ['Gadget D', 'Gadgets', 100]]);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $preview = app(ProductImportService::class)->preview($path, $shop->id);
        $this->assertTrue(collect($preview['notices'])->contains(fn ($n) => str_contains($n, 'room for 2 more')));

        $outcome = app(ProductImportService::class)->commit($path, $shop->id);
        @unlink($path);

        $this->assertTrue($outcome['success']);
        $this->assertSame(2, $outcome['createdProducts']);
        $this->assertSame(2, $outcome['limitSkippedProducts']);
        $this->assertSame(['Row 4: "Gadget C"', 'Row 5: "Gadget D"'], $outcome['limitSkippedLines']);
        $this->assertSame(3, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_staff_users_are_capped_and_inactive_ones_do_not_count(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['staff_users' => 1]));
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $this->actingAs($owner);

        Livewire::test('users.index')->call('openCreate')->set('name', 'First')->set('email', 'first@example.pk')->set('roleId', $role->id)->call('save')->assertHasNoErrors();
        Livewire::test('users.index')->call('openCreate')->set('name', 'Second')->set('email', 'second@example.pk')->set('roleId', $role->id)->call('save');
        $this->assertSame(0, User::where('name', 'Second')->count());

        User::where('name', 'First')->update(['is_active' => false]);
        Livewire::test('users.index')->call('openCreate')->set('name', 'Second')->set('email', 'second@example.pk')->set('roleId', $role->id)->call('save')->assertHasNoErrors();
        $this->assertSame(1, User::where('name', 'Second')->count());
    }

    public function test_sales_are_never_blocked_and_going_over_is_flagged(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['sales_per_month' => 1]));
        $product = $this->product($shop, 'Cable', 3);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 3, 'cost_price' => 50, 'selling_price' => 100, 'quantity_remaining' => 3, 'total_cost' => 150, 'payment_status' => 'paid', 'amount_paid' => 150, 'stock_date' => '2026-01-01']);

        foreach (range(1, 3) as $i) {
            CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0, null, $owner->id, now()->toDateString(), 100, $shop->id);
        }

        $this->assertSame(3, Sale::withoutGlobalScopes()->count());
        $meter = collect(app(Entitlements::class)->meters($shop, fresh: true))->firstWhere('key', 'sales_per_month');
        $this->assertSame(3, $meter['used']);
        $this->assertTrue($meter['over']);

        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('Over sales limit');
        $this->actingAs($owner)->get(route('billing.index'))->assertSee('sales keep working');
    }

    public function test_monthly_counters_follow_the_pakistan_calendar_month(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['marketing_posts_per_month' => 1], ['social' => true]));
        Storage::fake('public', ['url' => 'https://shop.test/storage']);
        $product = $this->product($shop, 'Cable', 2);
        $composer = app(PostComposer::class);

        $this->travelTo(Carbon::parse('2026-11-30 18:30:00', 'UTC'));
        $composer->createProductPost($shop, $owner->id, $product);
        $composer->createQrCode($shop, $owner->id, 'Counter');

        $this->expectExceptionObject(new \App\Exceptions\UsageLimitReached(LimitKey::MarketingPostsPerMonth, 1));

        try {
            $composer->createProductPost($shop, $owner->id, $product);
        } finally {
            $this->travelTo(Carbon::parse('2026-11-30 19:30:00', 'UTC'));
            $this->assertSame(0, app(Entitlements::class)->usage($shop, LimitKey::MarketingPostsPerMonth));
            $composer->createProductPost($shop, $owner->id, $product);
        }
    }

    public function test_automatic_whatsapp_messages_pause_at_the_limit_but_order_updates_still_go(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $shop->update(['plan_id' => $this->planWith(['whatsapp_messages_per_month' => 1], ['whatsapp' => true])->id]);
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'shop_channel_connection_id' => $connection->id, 'wa_id' => '923001234567', 'last_inbound_at' => now()]);
        $messenger = app(CustomerMessenger::class);

        $first = $messenger->deliver($connection, $contact, NotificationType::PaymentReminder, null, 'Reminder 1', [], 'payment_reminder', []);
        $this->assertSame(NotificationStatus::Sent, $first->status);

        $second = $messenger->deliver($connection, $contact, NotificationType::ReviewRequest, null, 'Please review', [], 'review_request', []);
        $this->assertSame(NotificationStatus::Failed, $second->status);
        $this->assertStringContainsString('message limit reached', $second->error);

        $update = $messenger->deliver($connection, $contact, NotificationType::OrderUpdate, null, 'Your order is packed', [], 'order_update', []);
        $this->assertSame(NotificationStatus::Sent, $update->status);

        Http::assertSentCount(2);
    }

    public function test_plan_features_switch_shops_on_and_admin_overrides_stick(): void
    {
        $plan = $this->planWith([], ['whatsapp' => true, 'ads' => false, 'barcode' => true]);
        [$shop] = $this->shopOn(null, ['ads_enabled' => true]);
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])->set('planId', (string) $plan->id)->call('saveSubscription')->assertHasNoErrors();
        $shop->refresh();
        $this->assertTrue($shop->whatsapp_enabled);
        $this->assertFalse($shop->ads_enabled);

        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleAdsEnabled')->assertSee('Use plan default');
        $this->assertTrue($shop->fresh()->ads_enabled);
        $this->assertSame(['ads' => true], $shop->fresh()->feature_overrides);

        Livewire::test('admin.plans')->call('openEdit', $plan->id)->set('featureFlags.whatsapp', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($shop->fresh()->whatsapp_enabled);
        $this->assertTrue($shop->fresh()->ads_enabled);

        Livewire::test('admin.shops.show', ['shop' => $shop->fresh()])->call('usePlanDefault', FeatureKey::Ads->value);
        $this->assertFalse($shop->fresh()->ads_enabled);
        $this->assertNull($shop->fresh()->feature_overrides);
    }

    public function test_admin_sets_plan_limits_and_per_shop_overrides(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $this->actingAs($admin);

        Livewire::test('admin.plans')->call('openCreate')->set('name', 'Mini')->set('price', '999')
            ->set('features', [['label' => 'Basics', 'included' => true]])
            ->set('limits.products', '50')->set('limits.staff_users', '')->set('featureFlags.import', true)
            ->call('save')->assertHasNoErrors();

        $plan = Plan::where('name', 'Mini')->sole();
        $this->assertSame(50, $plan->limits['products']);
        $this->assertNull($plan->limits['staff_users']);
        $this->assertTrue($plan->feature_flags['import']);

        [$shop] = $this->shopOn($plan);
        Livewire::test('admin.shops.show', ['shop' => $shop])->assertSee('Plan: 50')->set('productsAllowed', '75')->set('messagesLimit', '10')->call('saveSubscription')->assertHasNoErrors();

        $shop->refresh();
        $this->assertSame(['products' => 75, 'whatsapp_messages_per_month' => 10], $shop->limit_overrides);
        $this->assertSame(75, app(Entitlements::class)->limit($shop, LimitKey::Products));
        $this->assertNull(app(Entitlements::class)->limit($shop, LimitKey::StaffUsers));
    }

    public function test_owners_see_meters_and_a_warning_near_the_limit(): void
    {
        [$shop, $owner] = $this->shopOn($this->planWith(['products' => 5]));
        foreach (range(1, 4) as $i) {
            $this->product($shop, "Item {$i}");
        }

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('Usage')->assertSee('4 / 5');
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Nearing your plan limit');

        $this->product($shop, 'Item 5');
        app(Entitlements::class)->forgetMeters($shop);
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Plan limit reached');
    }
}
