<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('plan_type')->nullable()->after('address');
            $table->string('subscription_status')->default('inactive')->after('plan_type');
            $table->date('subscription_start_date')->nullable()->after('subscription_status');
            $table->unsignedInteger('products_allowed')->nullable()->after('subscription_start_date');
            $table->unsignedInteger('sales_allowed')->nullable()->after('products_allowed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'plan_type',
                'subscription_status',
                'subscription_start_date',
                'products_allowed',
                'sales_allowed',
            ]);
        });
    }
};
