<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_channel_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('external_id', 64);
            $table->string('waba_id', 64)->nullable();
            $table->string('display_name')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 20)->default('connected');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['shop_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_channel_connections');
    }
};
