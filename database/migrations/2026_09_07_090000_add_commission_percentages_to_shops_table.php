<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('sim_sale_commission_percent', 5, 2)->nullable()->after('disabled_screens');
            $table->decimal('balance_load_commission_percent', 5, 2)->nullable()->after('disabled_screens');
            $table->decimal('wallet_load_commission_percent', 5, 2)->nullable()->after('disabled_screens');
            $table->decimal('bills_commission_percent', 5, 2)->nullable()->after('disabled_screens');
            $table->decimal('nadra_verification_commission_percent', 5, 2)->nullable()->after('disabled_screens');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'sim_sale_commission_percent',
                'balance_load_commission_percent',
                'wallet_load_commission_percent',
                'bills_commission_percent',
                'nadra_verification_commission_percent',
            ]);
        });
    }
};
