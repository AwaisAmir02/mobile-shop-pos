<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online P1 (O1-O5): delivery details and a delivery charge on a sale, parcels
 * typed in by hand (booked on the courier's own portal), courier payouts that
 * settle many cash-on-delivery parcels at once, and what a returned parcel cost.
 *
 * Existing rows read exactly as before: delivery_charge is 0, every existing
 * parcel keeps its courier connection and is not cod_tracked, so no current
 * sale leaves anyone's dues. The only backfill is consignee_phone_key, a
 * search column worked out from the phone already stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        // O2: part of the sale's total and revenue; the goods lines don't include it.
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('delivery_charge', 10, 2)->default(0)->after('discount_amount');
        });

        // O1: who the order goes to, for a sale rung up from a message or a call.
        Schema::create('sale_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('phone', 20);
            $table->string('phone_key', 15)->nullable();
            $table->string('address', 300);
            $table->string('city', 60)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'phone_key']);
        });

        // O4: one payout from a courier covering many parcels, net of its deductions.
        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('courier', 20);
            $table->string('reference', 60)->nullable();
            $table->date('settlement_date');
            $table->decimal('cod_total', 12, 2);
            $table->decimal('charges_total', 12, 2)->default(0);
            $table->decimal('tax_withheld', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained('shop_accounts')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'settlement_date']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            // A parcel booked on the courier's own portal has no connection here.
            $table->foreignId('courier_connection_id')->nullable()->change();
            $table->string('courier_name', 60)->nullable()->after('courier');
            $table->string('consignee_phone_key', 15)->nullable()->after('consignee_phone');
            // The courier collects this COD for the shop and pays it through a settlement (O4).
            $table->boolean('cod_tracked')->default(false)->after('cod_amount');
            $table->foreignId('courier_settlement_id')->nullable()->after('cod_tracked')->constrained('courier_settlements')->nullOnDelete();
            $table->decimal('return_charge', 10, 2)->nullable()->after('return_received_at');

            $table->index(['shop_id', 'consignee_phone_key']);
        });

        Schema::create('courier_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cod_collected', 12, 2)->default(0);
            $table->decimal('courier_charge', 10, 2)->default(0);
            $table->decimal('return_charge', 10, 2)->default(0);
            $table->foreignId('sale_payment_id')->nullable()->constrained('sale_payments')->nullOnDelete();
            $table->timestamps();
        });

        foreach (DB::table('shipments')->whereNotNull('consignee_phone')->get(['id', 'consignee_phone']) as $row) {
            DB::table('shipments')->where('id', $row->id)->update(['consignee_phone_key' => \App\Support\PhoneNumber::normalize((string) $row->consignee_phone)]);
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'consignee_phone_key']);
            $table->dropConstrainedForeignId('courier_settlement_id');
            $table->dropColumn(['courier_name', 'consignee_phone_key', 'cod_tracked', 'return_charge']);
        });

        Schema::dropIfExists('courier_settlement_items');
        Schema::dropIfExists('courier_settlements');
        Schema::dropIfExists('sale_deliveries');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('delivery_charge');
        });
    }
};
