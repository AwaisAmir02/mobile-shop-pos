<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallet Provider was fully absorbed into Shop Account (Send From /
     * Received Into) — WalletLoad.provider is a plain string column with
     * no foreign key to this table, so dropping it touches no other data.
     * WalletLoad.provider is now auto-derived from the selected account's
     * provider_type at save time instead of being separately picked.
     */
    public function up(): void
    {
        Schema::dropIfExists('wallet_providers');
    }

    public function down(): void
    {
        Schema::create('wallet_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });
    }
};
