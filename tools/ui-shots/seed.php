<?php

/*
 * Demo data for the design overhaul's before/after screenshots.
 * Fills the local TESTING database only, and refuses to run against anything else.
 * See README.md in this folder.
 */

if (PHP_SAPI !== 'cli') {
    exit("Command line only\n");
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = config('database.connections.mysql');

if ($connection['database'] !== 'mobile_shop_pos_testing' || $connection['host'] !== '127.0.0.1') {
    exit("Refusing: not the local testing database\n");
}

use App\Actions\CreateSale;
use App\Actions\CreateUdhaarTransaction;
use App\Actions\SavePurchaseOrder;
use App\Actions\StartLedger;
use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\MainCategory;
use App\Models\NadraVerification;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Sale;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\Shop;
use App\Models\SimSale;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletLoad;
use App\Services\ProductGroupService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

$failures = 0;
$try = function (string $label, callable $fn) use (&$failures) {
    try {
        return $fn();
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL $label: ".substr($e->getMessage(), 0, 200)."\n";

        return null;
    }
};

$shop = Shop::firstOrFail();
$flags = [
    'import_enabled', 'barcode_enabled', 'branding_enabled', 'services_enabled', 'variants_enabled',
    'measured_units_enabled', 'expiry_tracking_enabled', 'purchase_orders_enabled', 'payroll_enabled',
    'online_store_enabled', 'ledger_enabled', 'whatsapp_ordering_enabled', 'whatsapp_messaging_enabled',
    'social_enabled', 'ads_enabled',
];
foreach ($flags as $flag) {
    $shop->{$flag} = true;
}
$shop->name = 'Madina Mobile & General Store';
$shop->products_allowed = 1000;
$shop->sales_allowed = 5000;
$shop->save();

$owner = User::where('email', 'shop@example.com')->firstOrFail();
Auth::login($owner);
MainCategory::ensureDefaultsExist($shop->id);

// A limited-role cashier, to check what staff see.
$cashierRole = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales', 'customers', 'udhaar', 'balance-loads', 'products']]);
User::factory()->staff()->create([
    'name' => 'Demo Cashier', 'email' => 'cashier@example.com', 'password' => Hash::make('password'),
    'shop_id' => $shop->id, 'role_id' => $cashierRole->id,
]);

$vendors = [];
foreach ([['Al-Rehman Traders', '0301-1111111'], ['City Mobile Distributors', '0302-2222222'], ['Karachi Accessories', '0303-3333333']] as [$name, $phone]) {
    $vendors[] = Vendor::create(['shop_id' => $shop->id, 'name' => $name, 'phone' => $phone]);
}

$customers = [];
foreach (['Bilal Ahmed', 'Ayesha Khan', 'Usman Tariq', 'Hina Malik', 'Faisal Raza', 'Sana Iqbal'] as $i => $name) {
    $customers[] = Customer::create(['shop_id' => $shop->id, 'name' => $name, 'phone' => '0321-55500'.$i.$i]);
}

foreach (Product::where('stock_quantity', '>', 0)->get() as $i => $product) {
    $cost = $product->cost_price ?? 50;
    $try("batch {$product->name}", fn () => StockIn::create([
        'shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => $product->name, 'vendor_id' => $vendors[$i % 3]->id,
        'quantity' => $product->stock_quantity, 'quantity_remaining' => $product->stock_quantity, 'unit' => $product->unit,
        'cost_price' => $cost, 'selling_price' => $product->price ?: 150,
        'total_cost' => $cost * $product->stock_quantity,
        'amount_paid' => $i % 2 ? 0 : $cost * $product->stock_quantity,
        'payment_status' => $i % 2 ? 'unpaid' : 'paid', 'stock_date' => now()->subDays(10 + $i)->toDateString(),
    ]));
}

$try('variant group', function () use ($shop) {
    $service = app(ProductGroupService::class);
    $service->addVariants($service->createGroup($shop->id, 'Cotton Kurta', 'accessory', 'Case / Cover'), ['S / White', 'M / White', 'L / White', 'S / Navy', 'M / Navy', 'L / Navy'], 2400, 1500);
});

foreach (Product::whereNotNull('product_group_id')->limit(3)->get() as $variant) {
    $variant->update(['stock_quantity' => 15]);
    StockIn::create([
        'shop_id' => $shop->id, 'product_id' => $variant->id, 'product_name' => $variant->name, 'vendor_id' => $vendors[0]->id,
        'quantity' => 15, 'quantity_remaining' => 15, 'unit' => $variant->unit, 'cost_price' => 1500, 'selling_price' => 2400,
        'total_cost' => 22500, 'amount_paid' => 0, 'payment_status' => 'unpaid',
        'stock_date' => now()->subDays(3)->toDateString(), 'expiry_date' => now()->addDays(20)->toDateString(),
    ]);
}

$sellable = Product::where('stock_quantity', '>', 5)->get();
for ($i = 0; $i < 28; $i++) {
    $product = $sellable[$i % $sellable->count()];
    $try("sale $i", fn () => CreateSale::handle(
        lines: [['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]],
        invoiceDiscount: 0.0,
        customerId: $i % 3 === 0 ? $customers[$i % 6]->id : null,
        userId: $owner->id,
        saleDate: now()->subDays($i % 12)->toDateString(),
        amountPaid: $i % 3 === 0 ? 0.0 : (float) $product->currentSellingPrice(),
    ));
}

foreach (['Shop rent', 'Electricity bill', 'Tea & snacks', 'Internet'] as $i => $description) {
    $try("expense $description", fn () => Expense::create([
        'shop_id' => $shop->id, 'user_id' => $owner->id, 'description' => $description, 'amount' => [45000, 12800, 950, 3500][$i],
        'expense_date' => now()->subDays($i)->toDateString(), 'status' => 'active', 'payment_method' => 'cash',
    ]));
}

$try('udhaar', fn () => CreateUdhaarTransaction::handle($customers[1], 'given', '5000', now()->subDays(4)->toDateString(), 'Cash udhaar'));
$try('employee', fn () => Employee::create([
    'shop_id' => $shop->id, 'name' => 'Imran Ali', 'phone' => '0333-1234567', 'designation' => 'Salesman',
    'salary_type' => 'fixed', 'rate' => 35000, 'base_salary' => 35000, 'status' => 'active', 'joined_on' => now()->subYear()->toDateString(),
]));
$try('purchase order', fn () => SavePurchaseOrder::handle(null, [
    'vendor_id' => $vendors[0]->id, 'order_date' => now()->toDateString(), 'expected_date' => now()->addDays(5)->toDateString(), 'note' => null,
], [['product_id' => $sellable[0]->id, 'quantity' => 10, 'cost_price' => (float) ($sellable[0]->cost_price ?? 100)]], $owner->id));

// Enough rows in each counter history to show tables and paging. Written straight
// to the models because this is display data only.
$billCategory = $try('bill category', fn () => BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']));
$billProvider = $try('bill provider', fn () => BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $billCategory->id, 'name' => 'K-Electric', 'region' => 'Karachi']));
$serviceCategory = $try('service category', fn () => ServiceCategory::create(['shop_id' => $shop->id, 'name' => 'Printing']));
$serviceItem = $try('service item', fn () => ServiceItem::create(['shop_id' => $shop->id, 'service_category_id' => $serviceCategory->id, 'name' => 'Photocopy']));

for ($i = 0; $i < 18; $i++) {
    $money = fn (float $amount, float $fee = 0) => [
        'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $i % 4 === 0 ? $customers[$i % 6]->id : null,
        'amount' => $amount, 'discount' => 0, 'total' => $amount + $fee, 'payment_status' => $i % 4 === 0 ? 'unpaid' : 'paid', 'amount_paid' => $i % 4 === 0 ? 0 : $amount + $fee,
    ];
    $networks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];
    $network = $networks[$i % 4];

    $try("sim $i", fn () => SimSale::create($money(500, 50) + ['network' => $network, 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'is_duplicate' => false, 'sim_number' => '0300123'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'fee' => 50]));
    $try("balance $i", fn () => BalanceLoad::create($money(100 + $i * 10, 10) + ['network' => $network, 'load_type' => 'balance', 'phone_number' => '0310123'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'fee' => 10]));
    $try("wallet $i", fn () => WalletLoad::create($money(2000 + $i * 100, 40) + ['direction' => $i % 2 ? 'cash_in' : 'cash_out', 'provider' => $i % 2 ? 'JazzCash' : 'Easypaisa', 'account_name' => $customers[$i % 6]->name, 'account_number' => '0320123'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'fee' => 40, 'net_amount' => 2000 + $i * 100]));
    $try("bill $i", fn () => BillPayment::create($money(1500 + $i * 75, 30) + ['bill_category_id' => $billCategory->id, 'bill_provider_id' => $billProvider->id, 'consumer_number' => '40012345'.$i, 'consumer_name' => $customers[$i % 6]->name, 'fee' => 30]));
    $try("nadra $i", fn () => NadraVerification::create($money(300, 50) + ['phone_number' => '0330123'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'cnic_number' => '42101-1234567-'.($i % 10), 'fee' => 50]));
    $try("repair $i", fn () => Repair::create($money(1200 + $i * 50) + ['category' => 'Mobile', 'sub_category' => 'Screen', 'description' => 'iPhone 13, screen cracked', 'service_charges' => 800, 'products_charges' => 400 + $i * 50]));
    $try("service $i", fn () => ServicePayment::create([
        'shop_id' => $shop->id, 'user_id' => $owner->id, 'service_category_id' => $serviceCategory->id, 'service_item_id' => $serviceItem->id,
        'amount' => 50 + $i * 5, 'payment_status' => 'paid', 'amount_paid' => 50 + $i * 5,
    ]));
}

// Orders waiting in the inboxes, so the order lists and detail pages have
// something to show. Pending only: nothing is accepted, so no sale or stock moves.
$contact = $try('whatsapp contact', fn () => \App\Models\WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923001112223', 'customer_id' => $customers[0]->id, 'last_inbound_at' => now()]));
foreach ([[$sellable[0], 1], [$sellable[1] ?? $sellable[0], 2]] as $n => [$product, $quantity]) {
    $try("whatsapp order $n", function () use ($shop, $contact, $product, $quantity, $n) {
        $order = \App\Models\WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => \App\Enums\WhatsAppOrderStatus::Pending, 'submitted_at' => now()->subHours($n + 1)]);
        \App\Models\WhatsAppOrderItem::create(['shop_id' => $shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'quoted_unit_price' => (float) $product->price]);
    });
}
$connection = $try('store connection', fn () => \App\Models\StoreConnection::create(['shop_id' => $shop->id, 'public_id' => 'demo'.substr(md5((string) $shop->id), 0, 12), 'name' => 'Demo web store', 'signing_secret' => 'demo-secret', 'created_by' => $owner->id]));
$try('store order', function () use ($shop, $connection, $sellable) {
    $order = \App\Models\StoreOrder::create(['shop_id' => $shop->id, 'store_connection_id' => $connection->id, 'external_order_id' => 'WEB-1001', 'event_id' => 'evt-demo-1', 'status' => 'pending', 'customer_name' => 'Zara Ahmed', 'customer_phone' => '0300-7654321', 'delivery_address' => 'House 12, Street 4, Gulshan', 'delivery_area' => 'Karachi', 'payment_method' => 'cod', 'placed_at' => now()->subHour()]);
    \App\Models\StoreOrderItem::create(['shop_id' => $shop->id, 'store_order_id' => $order->id, 'product_id' => $sellable[0]->id, 'product_name' => $sellable[0]->name, 'quantity' => 1, 'unit_price' => (float) $sellable[0]->price]);
});

$try('start ledger', fn () => StartLedger::handle($shop->fresh(), []));

// D13: a support conversation (shop question, admin answer with the ticket in
// progress) and a second unanswered ticket, so both inboxes have something to show.
$try('support tickets', function () use ($owner) {
    $ticket = app(\App\Actions\Support\OpenSupportTicket::class)->handle($owner, "When I press Complete Sale the receipt doesn't print. It worked yesterday.", [], '/sales/history', 'Sales History', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36');
    $admin = User::where('is_super_admin', true)->firstOrFail();
    Auth::login($admin);
    app(\App\Actions\Support\ReplyToSupportTicket::class)->handle($ticket, $admin, 'Thanks for the details. Which printer are you using, and does a test page print from Windows?');
    $ticket->update(['status' => \App\Enums\SupportTicketStatus::InProgress]);
    Auth::login($owner);
    app(\App\Actions\Support\OpenSupportTicket::class)->handle($owner, 'How do I add a second branch?', [], '/branches', 'Branches', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36');
});

// Cash & Balances: two accounts with opening balances, wallet loads through them,
// a deposit into the bank and a till count, so every tab has something to show.
$try('cash and balances', function () use ($shop, $owner) {
    $jazz = \App\Models\ShopAccount::firstOrCreate(['shop_id' => $shop->id, 'name' => 'JazzCash Retailer'], ['provider_type' => 'JazzCash']);
    $bank = \App\Models\ShopAccount::firstOrCreate(['shop_id' => $shop->id, 'name' => 'Meezan Bank'], ['provider_type' => 'Bank']);
    $cash = 'cash:'.\App\Support\BranchContext::current()->mainBranchIdFor($shop->id);
    $start = now('Asia/Karachi')->subDays(2)->toDateString();

    \App\Actions\MoneyEntries::opening($shop->id, $cash, 25000, $start, $owner->id);
    \App\Actions\MoneyEntries::opening($shop->id, 'account:'.$jazz->id, 60000, $start, $owner->id);
    \App\Actions\MoneyEntries::opening($shop->id, 'account:'.$bank->id, 150000, $start, $owner->id);

    foreach ([['cash_in', 5000, 75, 5075], ['cash_out', 3000, 45, 3045], ['cash_in', 12000, 180, 12180], ['cash_out', 8000, 120, 7000]] as [$direction, $amount, $fee, $paid]) {
        $load = WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => $direction, 'provider' => 'JazzCash', 'shop_account_id' => $jazz->id,
            'account_name' => $direction === 'cash_in' ? 'Bilal Ahmed' : null, 'account_number' => $direction === 'cash_in' ? '03201234567' : null,
            'amount' => $amount, 'fee' => $fee, 'discount' => 0, 'net_amount' => $amount, 'total' => $amount + $fee,
            'payment_status' => $paid >= $amount + $fee ? 'paid' : 'partial', 'amount_paid' => 0]);
        \App\Actions\RecordModulePayment::handle($load, $paid, null, null, $owner->id);
    }

    \App\Actions\MoneyEntries::transfer($shop->id, $cash, 'account:'.$bank->id, 10000, now('Asia/Karachi')->toDateString(), $owner->id, 'Evening deposit');
    \App\Actions\MoneyEntries::count($shop->id, $cash, 20000, now('Asia/Karachi')->subDay()->toDateString(), $owner->id, 'Till count');
});

echo 'products='.Product::count().' sales='.Sale::count().' stockins='.StockIn::count().' failures='.$failures."\n";
