<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_channel_connection_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('language', 5);
            $table->string('name', 100);
            $table->string('category', 20);
            $table->string('meta_template_id', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_channel_connection_id', 'key', 'language'], 'whatsapp_templates_connection_key_language_unique');
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->nullable()->constrained('whatsapp_contacts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->nullableMorphs('notifiable');
            $table->string('status', 20);
            $table->string('delivery_mode', 20)->nullable();
            $table->string('template_key', 40)->nullable();
            $table->text('body')->nullable();
            $table->string('wamid')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'type', 'created_at']);
            $table->index(['customer_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('whatsapp_templates');
    }
};
