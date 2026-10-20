<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->decimal('service_charges', 10, 2)->nullable()->after('amount');
            $table->decimal('products_charges', 10, 2)->nullable()->after('service_charges');
        });
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->dropColumn(['service_charges', 'products_charges']);
        });
    }
};
