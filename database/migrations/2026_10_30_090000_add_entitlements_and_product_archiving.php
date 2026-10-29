<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('limits')->nullable()->after('features');
            $table->json('feature_flags')->nullable()->after('limits');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->json('limit_overrides')->nullable()->after('sales_allowed');
            $table->json('feature_overrides')->nullable()->after('limit_overrides');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->index(['shop_id', 'archived_at']);
        });

        $placeholders = [
            'Starter' => [
                ['products' => 500, 'staff_users' => 2, 'sales_per_month' => 1500, 'whatsapp_messages_per_month' => 0, 'marketing_posts_per_month' => 0],
                ['whatsapp' => false, 'social' => false, 'ads' => false, 'import' => true, 'barcode' => true, 'branding' => true, 'services' => true],
            ],
            'Growth' => [
                ['products' => 2000, 'staff_users' => 5, 'sales_per_month' => 5000, 'whatsapp_messages_per_month' => 1000, 'marketing_posts_per_month' => 0],
                ['whatsapp' => true, 'social' => false, 'ads' => false, 'import' => true, 'barcode' => true, 'branding' => true, 'services' => true],
            ],
            'Commerce' => [
                ['products' => null, 'staff_users' => 10, 'sales_per_month' => null, 'whatsapp_messages_per_month' => 3000, 'marketing_posts_per_month' => 60],
                ['whatsapp' => true, 'social' => true, 'ads' => true, 'import' => true, 'barcode' => true, 'branding' => true, 'services' => true],
            ],
        ];

        foreach ($placeholders as $name => [$limits, $features]) {
            DB::table('plans')->where('name', $name)->whereNull('limits')->update([
                'limits' => json_encode($limits),
                'feature_flags' => json_encode($features),
            ]);
        }

        DB::table('shops')
            ->where(fn ($q) => $q->whereNotNull('products_allowed')->orWhereNotNull('sales_allowed'))
            ->orderBy('id')
            ->each(function ($shop) {
                DB::table('shops')->where('id', $shop->id)->update([
                    'limit_overrides' => json_encode(array_filter([
                        'products' => $shop->products_allowed,
                        'sales_per_month' => $shop->sales_allowed,
                    ], fn ($value) => $value !== null)),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['limit_overrides', 'feature_overrides']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['limits', 'feature_flags']);
        });
    }
};
