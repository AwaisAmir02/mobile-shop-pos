<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_channel_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_id', 20);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile_name')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->timestamp('marketing_opt_out_at')->nullable();
            $table->json('bot_state')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'wa_id']);
        });

        Schema::create('whatsapp_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('collecting');
            $table->decimal('quoted_total', 12, 2)->default(0);
            $table->string('customer_note', 500)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('origin_ref', 20)->nullable();
            $table->json('referral')->nullable();
            $table->timestamp('customer_notified_at')->nullable();
            $table->string('notify_error', 500)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'submitted_at']);
            $table->index(['whatsapp_contact_id', 'status']);
        });

        Schema::create('whatsapp_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_order_id')->constrained('whatsapp_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->decimal('quoted_unit_price', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_order_items');
        Schema::dropIfExists('whatsapp_orders');
        Schema::dropIfExists('whatsapp_contacts');
    }
};
