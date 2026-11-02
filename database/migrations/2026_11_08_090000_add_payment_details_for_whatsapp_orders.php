<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shown to WhatsApp customers who choose to pay online.
        Schema::table('shop_accounts', function (Blueprint $table) {
            $table->string('account_title', 120)->nullable()->after('provider_type');
            $table->string('account_number', 60)->nullable()->after('account_title');
        });

        // What the customer chose in chat (cod, pay_at_shop, online) — not a payment record.
        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->string('payment_choice', 20)->nullable()->after('delivery_phone');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->dropColumn('payment_choice');
        });

        Schema::table('shop_accounts', function (Blueprint $table) {
            $table->dropColumn(['account_title', 'account_number']);
        });
    }
};
