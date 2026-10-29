<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'notified_at', 'cancelled_at']);
            $table->index(['product_id', 'whatsapp_contact_id']);
        });

        Schema::create('customer_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->nullable()->constrained('whatsapp_contacts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_order_id')->nullable()->constrained('whatsapp_orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('display_name', 100)->nullable();
            $table->boolean('publish_consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->string('status', 20)->default('collecting');
            $table->timestamps();

            $table->index(['shop_id', 'status', 'publish_consent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reviews');
        Schema::dropIfExists('restock_subscriptions');
    }
};
