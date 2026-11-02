<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The contact's saved details, reused only after the customer confirms.
        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->string('delivery_name', 120)->nullable()->after('language');
            $table->string('delivery_address', 500)->nullable()->after('delivery_name');
            $table->string('delivery_area', 120)->nullable()->after('delivery_address');
            $table->string('delivery_phone', 20)->nullable()->after('delivery_area');
        });

        // The details given for one order (delivery_address already exists).
        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->string('delivery_name', 120)->nullable()->after('customer_note');
            $table->string('delivery_area', 120)->nullable()->after('delivery_address');
            $table->string('delivery_phone', 20)->nullable()->after('delivery_area');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_name', 'delivery_area', 'delivery_phone']);
        });

        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->dropColumn(['delivery_name', 'delivery_address', 'delivery_area', 'delivery_phone']);
        });
    }
};
