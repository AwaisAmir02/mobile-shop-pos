<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('ads_enabled')->default(false)->after('social_enabled');
        });

        Schema::create('meta_ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shop_channel_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketing_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('goal', 30);
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3);
            $table->decimal('daily_budget', 12, 2);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->json('targeting');
            $table->string('meta_campaign_id', 64)->nullable();
            $table->string('meta_adset_id', 64)->nullable();
            $table->string('meta_image_hash', 100)->nullable();
            $table->string('meta_creative_id', 64)->nullable();
            $table->string('meta_ad_id', 64)->nullable()->index();
            $table->string('effective_status', 40)->nullable();
            $table->text('review_feedback')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('launched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('launched_at')->nullable();
            $table->decimal('spend_cap_confirmed', 12, 2)->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('metrics_synced_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_campaigns');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('ads_enabled');
        });
    }
};
