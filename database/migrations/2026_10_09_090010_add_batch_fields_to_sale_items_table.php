<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('stock_in_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->decimal('cost_price', 10, 2)->nullable()->after('list_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_in_id');
            $table->dropColumn('cost_price');
        });
    }
};
