<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $shop = Shop::create([
            'name' => 'Demo Mobile Shop',
            'phone' => '0300-0000000',
            'email' => 'shop@example.com',
            'plan_type' => 'monthly',
            'subscription_status' => 'active',
            'subscription_start_date' => now()->startOfMonth()->toDateString(),
            'products_allowed' => 100,
            'sales_allowed' => 500,
        ]);

        User::factory()->create([
            'name' => 'Demo Shop Owner',
            'email' => 'shop@example.com',
            'shop_id' => $shop->id,
            'is_owner' => true,
        ]);

        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'shop_id' => null,
            'is_super_admin' => true,
        ]);

        $products = [
            ['type' => 'mobile', 'name' => 'Samsung Galaxy A15', 'price' => 42000, 'cost_price' => 37000, 'stock_quantity' => 12, 'details' => ['brand' => 'Samsung', 'model' => 'Galaxy A15', 'imei' => null]],
            ['type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'cost_price' => 130000, 'stock_quantity' => 3, 'details' => ['brand' => 'Apple', 'model' => 'iPhone 13', 'imei' => null]],
            ['type' => 'mobile', 'name' => 'Infinix Hot 40', 'price' => 28000, 'cost_price' => 24000, 'stock_quantity' => 0, 'details' => ['brand' => 'Infinix', 'model' => 'Hot 40', 'imei' => null]],
            ['type' => 'accessory', 'name' => 'Clear Silicone Case', 'price' => 500, 'cost_price' => 200, 'stock_quantity' => 40, 'details' => ['category' => 'Case / Cover']],
            ['type' => 'accessory', 'name' => '20W Fast Charger', 'price' => 1800, 'cost_price' => 1200, 'stock_quantity' => 4, 'details' => ['category' => 'Charger']],
            ['type' => 'sim', 'name' => 'Jazz Prepaid SIM', 'price' => 100, 'cost_price' => null, 'stock_quantity' => 25, 'details' => ['sim_type' => 'prepaid', 'sim_form' => 'physical', 'network' => 'Jazz']],
            ['type' => 'sim', 'name' => 'Zong eSIM Postpaid', 'price' => 0, 'cost_price' => null, 'stock_quantity' => 8, 'details' => ['sim_type' => 'postpaid', 'sim_form' => 'esim', 'network' => 'Zong']],
        ];

        foreach ($products as $product) {
            Product::create(['shop_id' => $shop->id, ...$product]);
        }
    }
}
