<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private-disk path of the payment screenshot/PDF the customer sent for an online order.
        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->string('payment_slip_path')->nullable()->after('payment_choice');
        });

        // The shop's own welcome / thank-you wording per language; null means the standard text.
        Schema::table('shops', function (Blueprint $table) {
            $table->json('whatsapp_messages')->nullable()->after('social_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('whatsapp_messages');
        });

        Schema::table('whatsapp_orders', function (Blueprint $table) {
            $table->dropColumn('payment_slip_path');
        });
    }
};
