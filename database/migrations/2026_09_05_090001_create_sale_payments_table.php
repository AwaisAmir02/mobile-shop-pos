<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->timestamps();

            $table->index(['shop_id', 'sale_id']);
        });

        // Payment tracking is new — every sale completed before this migration
        // was implicitly paid in full under the old (payment-less) flow, so
        // backfill one settling payment per historical sale rather than
        // having them all appear as newly-unpaid.
        $now = now();

        DB::table('sales')->orderBy('id')->chunk(500, function ($sales) use ($now) {
            $rows = $sales->map(fn ($sale) => [
                'shop_id' => $sale->shop_id,
                'sale_id' => $sale->id,
                'user_id' => $sale->user_id,
                'amount' => $sale->total,
                'payment_date' => Carbon::parse($sale->created_at)->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('sale_payments')->insert($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
