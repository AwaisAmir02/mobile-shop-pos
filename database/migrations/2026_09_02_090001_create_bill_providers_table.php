<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('region')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'bill_category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_providers');
    }
};
