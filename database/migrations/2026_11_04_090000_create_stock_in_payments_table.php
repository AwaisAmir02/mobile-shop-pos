<?php

use App\Support\StockInPaymentBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dated supplier payments against Stock-Ins. StockIn.amount_paid stays
     * the stored total; these rows are its dated history.
     */
    public function up(): void
    {
        Schema::create('stock_in_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_in_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->string('source');
            $table->timestamps();

            $table->index(['shop_id', 'stock_in_id']);
            $table->index(['shop_id', 'payment_date']);
        });

        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_in_payments');
    }
};
