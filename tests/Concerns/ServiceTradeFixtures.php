<?php

namespace Tests\Concerns;

use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Enums\MembershipKind;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A service business (Services group tests): the owner signed in (password
 * secret), two staff, a customer, a menu (Haircut Rs 800 / 30 min, Facial
 * Rs 2,500 / 60 min, Yoga class Rs 500 / 60 min for 3), one product with
 * stock (Shampoo Rs 1,200, cost Rs 700, 10 from one batch), and for gyms a
 * Monthly membership (Rs 5,000, 1 month, freeze up to 15 days) and a 10
 * session package (Rs 20,000).
 */
trait ServiceTradeFixtures
{
    protected Shop $shop;

    protected User $owner;

    protected ShopAccount $bank;

    protected Employee $ali;

    protected Employee $sara;

    protected Customer $client;

    protected ServiceCategory $hair;

    protected ServiceItem $haircut;

    protected ServiceItem $facial;

    protected ServiceItem $yoga;

    protected Product $shampoo;

    protected MembershipPlan $monthly;

    protected MembershipPlan $tenSessions;

    protected function openServiceShop(string $category = 'salon', array $attributes = []): void
    {
        // Midday UTC: the shop's day (Karachi) and the server's day agree.
        Carbon::setTestNow('2026-11-10 08:00:00');

        $this->shop = Shop::create(['name' => 'Glow '.$category, 'business_category' => $category, 'phone' => '0421112223'] + $attributes);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'password' => Hash::make('secret')]);
        $this->actingAs($this->owner);
        $this->bank = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);
        $this->ali = Employee::create(['shop_id' => $this->shop->id, 'name' => 'Ali', 'designation' => 'Stylist', 'rate' => 30000, 'joined_on' => '2026-01-01', 'takes_appointments' => true]);
        $this->sara = Employee::create(['shop_id' => $this->shop->id, 'name' => 'Sara', 'designation' => 'Trainer', 'rate' => 25000, 'joined_on' => '2026-01-01', 'takes_appointments' => true]);
        $this->client = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Hina', 'phone' => '03001234567']);
        $this->hair = ServiceCategory::create(['shop_id' => $this->shop->id, 'name' => 'Hair']);
        $skin = ServiceCategory::create(['shop_id' => $this->shop->id, 'name' => 'Skin']);
        $this->haircut = ServiceItem::create(['shop_id' => $this->shop->id, 'service_category_id' => $this->hair->id, 'name' => 'Haircut', 'price' => 800, 'duration_minutes' => 30]);
        $this->facial = ServiceItem::create(['shop_id' => $this->shop->id, 'service_category_id' => $skin->id, 'name' => 'Facial', 'price' => 2500, 'duration_minutes' => 60]);
        $this->yoga = ServiceItem::create(['shop_id' => $this->shop->id, 'service_category_id' => $skin->id, 'name' => 'Yoga class', 'price' => 500, 'duration_minutes' => 60, 'capacity' => 3]);
        $this->shampoo = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Shampoo', 'price' => 1200, 'cost_price' => 700, 'stock_quantity' => 0, 'details' => ['category' => 'Retail']]);
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Supplier']);
        CreateStockIn::handle([['product_id' => $this->shampoo->id, 'quantity' => 10, 'cost_price' => 700, 'selling_price' => 1200]], $vendor->id, 'paid', null, '2026-11-01', null, $this->owner->id, paymentMethod: 'cash');
        $this->shampoo->refresh();
        $this->monthly = MembershipPlan::create(['shop_id' => $this->shop->id, 'name' => 'Monthly', 'kind' => MembershipKind::Time, 'price' => 5000, 'duration_value' => 1, 'duration_unit' => 'months', 'max_freeze_days' => 15]);
        $this->tenSessions = MembershipPlan::create(['shop_id' => $this->shop->id, 'name' => '10 Facials', 'kind' => MembershipKind::Sessions, 'price' => 20000, 'sessions' => 10, 'duration_value' => 6, 'duration_unit' => 'months', 'service_item_ids' => [$this->facial->id]]);
    }

    /** A service line as CreateSale takes it. */
    protected function serviceLine(ServiceItem $item, ?Employee $by = null, array $extra = []): array
    {
        return $extra + ['kind' => 'service', 'service_item_id' => $item->id, 'unit_price' => (float) $item->price, 'quantity' => 1, 'discount' => 0, 'performed_by' => $by?->id];
    }

    /** A bill through CreateSale with services (and optional products), paid as given. */
    protected function bill(array $services, array $products = [], ?Customer $customer = null, ?float $paid = null, float $invoiceDiscount = 0, array $prepaid = [], ?string $date = null): Sale
    {
        $date ??= now()->toDateString();
        $lines = array_map(fn ($p) => ['product_id' => $p[0]->id, 'quantity' => $p[1], 'discount' => 0.0, 'custom_price' => $p[2] ?? null], $products);

        return CreateSale::handle(
            lines: $lines, invoiceDiscount: $invoiceDiscount, customerId: ($customer ?? $this->client)->id, userId: $this->owner->id,
            saleDate: $date, amountPaid: $paid ?? 0.0, paymentMethod: ($paid ?? 0) > 0 ? 'cash' : null,
            services: $services, prepaid: $prepaid,
        );
    }
}
