<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's online store. "Store" here, because shop_channel_connections already
 * means the shop's WhatsApp and Meta accounts. Included in Commerce and sold as
 * an add-on to the other plans. Plans get an explicit false, because a plan with
 * no entry for a feature never turns it off on a lapse or downgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 32)->unique();
            $table->string('driver', 20)->default('api');
            $table->string('name', 100);
            $table->string('status', 20)->default('active');
            $table->text('signing_secret');
            $table->text('previous_signing_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->boolean('price_sync')->default(false);
            $table->unsignedInteger('safety_buffer')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'removed_at']);
        });

        Schema::create('store_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_connection_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('external_sku', 100)->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();

            $table->unique(['store_connection_id', 'product_id']);
            $table->unique(['store_connection_id', 'external_sku']);
            $table->index(['store_connection_id', 'updated_at']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('online_store_enabled')->default(false)->after('payroll_enabled');
        });

        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];

            if ($flags !== []) {
                DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode($flags + ['online_store' => false])]);
            }
        }

        foreach (DB::table('plans')->where('name', 'Commerce')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode(['online_store' => true] + $flags)]);
            DB::table('shops')->where('plan_id', $plan->id)->update(['online_store_enabled' => true]);
        }

        if (! DB::table('addons')->where('name', 'Online Store')->exists()) {
            $now = now();

            DB::table('addons')->insert([
                'name' => 'Online Store',
                'description' => 'Connect your online store: publish products and live stock to it, and receive its orders here.',
                'kind' => 'feature_unlock',
                'limit_key' => null,
                'quantity' => null,
                'feature_keys' => json_encode(['online_store']),
                'price' => 1000,
                'billing' => 'monthly',
                'is_active' => true,
                'sort_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Anything already bought stays in the catalogue, so a purchase never loses its add-on. */
    public function down(): void
    {
        DB::table('addons')
            ->where('name', 'Online Store')
            ->whereNotIn('id', DB::table('shop_addons')->whereNotNull('addon_id')->select('addon_id'))
            ->delete();

        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            unset($flags['online_store']);
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => $flags === [] ? null : json_encode($flags)]);
        }

        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('online_store_enabled'));
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('store_api_tokens');
        Schema::dropIfExists('store_connections');
    }
};
