<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->string('language', 5)->nullable()->after('profile_name');
        });

        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 30)->nullable()->after('status');
            $table->timestamp('fulfillment_updated_at')->nullable()->after('fulfillment_status');
            $table->timestamp('delivered_at')->nullable()->after('fulfillment_updated_at');
            $table->timestamp('review_requested_at')->nullable()->after('delivered_at');
            $table->index(['shop_id', 'delivered_at', 'review_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'delivered_at', 'review_requested_at']);
            $table->dropColumn(['fulfillment_status', 'fulfillment_updated_at', 'delivered_at', 'review_requested_at']);
        });

        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
