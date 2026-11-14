<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty: a product's default (months + brand or shop), copied onto each
 * sale line when sold, with the line's IMEI/serial, so later product edits
 * never change a warranty already given.
 *
 * Instalments: a due-date schedule for what is left on a sale after the down
 * payment. Money still moves only through sale_payments; the schedule only
 * says when each part falls due (App\Support\InstallmentSchedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('shelf_life_days');
            $table->string('warranty_type', 10)->nullable()->after('warranty_months');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('serial_number', 50)->nullable()->after('product_type');
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('serial_number');
            $table->string('warranty_type', 10)->nullable()->after('warranty_months');
            $table->index(['shop_id', 'serial_number']);
        });

        Schema::create('sale_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['sale_id', 'sequence']);
            $table->index(['shop_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_installments');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'serial_number']);
            $table->dropColumn(['serial_number', 'warranty_months', 'warranty_type']);
        });

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['warranty_months', 'warranty_type']));
    }
};
