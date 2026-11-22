<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prescription / controlled-drug register.
 *
 * The shop marks a medicine "Prescription only" or "Controlled" itself
 * (products.rx_control); no official list is kept. Every sale of one writes a
 * register entry per medicine: a serial number running per shop, the date,
 * the patient, the prescriber and the quantity. A sale with no prescriber is
 * an emergency sale and carries the reason. The prescription photo is kept
 * on the private disk (prescriptions/{shop}/...), served only through a
 * permission-checked route, and removed with the shop.
 *
 * Entries from one sale share its details (patient, prescriber, photo).
 * A sale not made at the register (WhatsApp, online) still gets its entries,
 * with the details left to fill in from the sale page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // null, 'prescription' or 'controlled'.
            $table->string('rx_control', 12)->nullable()->after('drap_reg_no');
        });

        Schema::create('sale_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('serial_number');
            $table->string('product_name');
            $table->string('control', 12);
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->date('dispensed_on');
            $table->string('patient_name', 120)->nullable();
            $table->string('prescriber_name', 120)->nullable();
            $table->string('prescriber_reg_no', 40)->nullable();
            $table->date('prescribed_on')->nullable();
            $table->boolean('is_emergency')->default(false);
            $table->string('emergency_reason', 255)->nullable();
            $table->string('photo_path')->nullable();
            // 'register', or where a sale made elsewhere came from.
            $table->string('source', 12)->default('register');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'serial_number']);
            $table->index(['shop_id', 'dispensed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_prescriptions');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('rx_control');
        });
    }
};
