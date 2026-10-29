<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->string('app_id', 64)->nullable();
            $table->text('app_secret')->nullable();
            $table->text('system_user_token')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->text('config')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_integrations');
    }
};
