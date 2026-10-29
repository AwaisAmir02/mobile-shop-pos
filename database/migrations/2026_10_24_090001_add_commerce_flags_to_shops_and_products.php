<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('services_enabled');
            $table->boolean('social_enabled')->default(false)->after('whatsapp_enabled');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('whatsapp_listed')->default(false)->after('stock_quantity');
            $table->index(['shop_id', 'whatsapp_listed']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'whatsapp_listed']);
            $table->dropColumn('whatsapp_listed');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_enabled', 'social_enabled']);
        });
    }
};
