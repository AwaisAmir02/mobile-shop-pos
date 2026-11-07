<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What has changed and not yet been told. One row however many times it changes.
        Schema::create('store_changes', function (Blueprint $table) {
            $table->foreignId('store_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->timestamp('created_at')->nullable();

            $table->primary(['store_connection_id', 'product_id', 'kind']);
        });

        Schema::create('store_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 32)->unique();
            $table->string('type', 40);
            $table->json('data');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at');
            $table->timestamp('give_up_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['store_connection_id', 'delivered_at', 'failed_at', 'next_attempt_at'], 'store_events_due');
            $table->index(['store_connection_id', 'id']);
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->timestamp('delivery_paused_at')->nullable()->after('callback_url');
            $table->timestamp('last_delivered_at')->nullable()->after('delivery_paused_at');
            $table->string('last_delivery_error', 255)->nullable()->after('last_delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropColumn(['delivery_paused_at', 'last_delivered_at', 'last_delivery_error']);
        });

        Schema::dropIfExists('store_events');
        Schema::dropIfExists('store_changes');
    }
};
