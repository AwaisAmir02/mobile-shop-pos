<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20)->default('product');
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->string('ref_code', 12)->unique();
            $table->string('status', 20)->default('draft');
            $table->string('image_path')->nullable();
            $table->string('story_image_path')->nullable();
            $table->foreignId('customer_review_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'kind', 'created_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('marketing_post_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('marketing_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketing_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_channel_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('surface', 20);
            $table->string('status', 20)->default('pending');
            $table->string('external_id')->nullable();
            $table->string('permalink', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('metrics_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['marketing_post_id', 'surface']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_post_targets');
        Schema::dropIfExists('marketing_post_products');
        Schema::dropIfExists('marketing_posts');
    }
};
