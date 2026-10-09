<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->nullable()->after('quantity');
            $table->decimal('selling_price', 10, 2)->nullable()->after('cost_price');
            $table->unsignedInteger('quantity_remaining')->default(0)->after('selling_price');
        });

        DB::statement('UPDATE stock_ins SET quantity_remaining = quantity');

        DB::statement('
            UPDATE stock_ins
            JOIN products ON products.id = stock_ins.product_id
            SET stock_ins.cost_price = stock_ins.total_cost / stock_ins.quantity
            WHERE stock_ins.total_cost IS NOT NULL AND stock_ins.quantity > 0
        ');

        DB::statement('
            UPDATE stock_ins
            JOIN products ON products.id = stock_ins.product_id
            SET stock_ins.selling_price = products.price
            WHERE stock_ins.product_id IS NOT NULL
        ');

        DB::statement("
            UPDATE stock_ins si
            JOIN (
                SELECT
                    id,
                    quantity,
                    SUM(quantity) OVER (PARTITION BY product_id ORDER BY stock_date, id) AS running_total,
                    SUM(quantity) OVER (PARTITION BY product_id) AS total_received
                FROM stock_ins
                WHERE status = 'active' AND product_id IS NOT NULL
            ) o ON o.id = si.id
            JOIN products p ON p.id = si.product_id
            SET si.quantity_remaining = GREATEST(0, LEAST(o.quantity, o.running_total - (o.total_received - p.stock_quantity)))
        ");

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->index(['product_id', 'quantity_remaining', 'stock_date', 'id'], 'stock_ins_fifo_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropIndex('stock_ins_fifo_lookup');
            $table->dropColumn(['cost_price', 'selling_price', 'quantity_remaining']);
        });
    }
};
