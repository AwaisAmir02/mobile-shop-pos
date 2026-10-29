<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('kind', 20);
            $table->string('limit_key', 40)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('feature_key', 40)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('billing', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('shop_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('name', 80);
            $table->string('kind', 20);
            $table->string('limit_key', 40)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('feature_key', 40)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('billing', 20);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('purchased_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'ends_at']);
        });

        $now = now();

        DB::table('addons')->insert([
            'name' => 'Extra 500 products',
            'description' => 'Add room for 500 more products until your plan renews.',
            'kind' => 'limit_pack',
            'limit_key' => 'products',
            'quantity' => 500,
            'feature_key' => null,
            'price' => 1000,
            'billing' => 'one_off',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_addons');
        Schema::dropIfExists('addons');
    }
};
