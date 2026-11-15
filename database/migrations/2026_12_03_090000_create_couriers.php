<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier booking with the shop's own courier accounts.
 *
 * courier_connections: one row per shop per courier, credentials encrypted.
 * shipments: a parcel booked for a sale, with its tracking number, label link
 * and the courier's latest status.
 *
 * The "couriers" plan feature starts on for the Commerce plan only, as the
 * online store connection did; every other plan gets it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('courier', 20);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 20)->default('connected');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'courier']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_connection_id')->constrained()->restrictOnDelete();
            $table->string('courier', 20);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_order_id')->nullable()->constrained('whatsapp_orders')->nullOnDelete();
            $table->foreignId('store_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 40);
            $table->string('consignee_name', 100);
            $table->string('consignee_phone', 20);
            $table->string('consignee_address', 300);
            $table->string('city', 60);
            $table->string('city_name', 100);
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->unsignedInteger('weight_grams');
            $table->unsignedSmallInteger('pieces')->default(1);
            $table->string('description', 250);
            $table->string('instructions', 250)->nullable();
            $table->string('tracking_number', 60);
            $table->string('label_url', 500)->nullable();
            $table->string('status', 20);
            $table->string('courier_status', 120)->nullable();
            $table->json('events')->nullable();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('return_received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['status', 'last_checked_at']);
            $table->index('tracking_number');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('couriers_enabled')->default(false)->after('online_store_enabled');
        });

        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];

            if ($flags !== []) {
                DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode($flags + ['couriers' => false])]);
            }
        }

        foreach (DB::table('plans')->where('name', 'Commerce')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode(['couriers' => true] + $flags)]);
            DB::table('shops')->where('plan_id', $plan->id)->update(['couriers_enabled' => true]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            unset($flags['couriers']);
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => $flags === [] ? null : json_encode($flags)]);
        }

        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('couriers_enabled'));
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('courier_connections');
    }
};
