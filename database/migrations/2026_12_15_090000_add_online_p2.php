<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online P2 (O6-O9): Shopify, WooCommerce and Daraz as store-connection drivers,
 * file imports from Daraz Seller Center, a JazzCash link that pays one order,
 * review requests for delivered parcels, and website requests.
 *
 * Existing rows read exactly as before: every connection stays driver 'api' with
 * no platform credentials, no payment or parcel gains a link or review token,
 * and nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        // O9: a platform store (myshop.myshopify.com, https://myshop.pk) and what we hold to call it.
        Schema::table('store_connections', function (Blueprint $table) {
            $table->string('external_domain', 255)->nullable()->after('driver');
            $table->text('credentials')->nullable()->after('external_domain');
            $table->json('settings')->nullable()->after('credentials');
            $table->timestamp('connected_at')->nullable()->after('settings');

            $table->index(['driver', 'external_domain']);
        });

        // O9: the platform's own ids for a mapped product (Shopify variant + inventory item, Woo product).
        Schema::table('store_products', function (Blueprint $table) {
            $table->string('external_ref', 100)->nullable()->after('external_sku');
            $table->string('inventory_ref', 100)->nullable()->after('external_ref');
        });

        // O6: each Seller Center file brought in, and what it did.
        Schema::create('marketplace_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('file_name', 255);
            $table->unsignedInteger('rows')->default(0);
            $table->json('summary')->nullable();
            $table->foreignId('courier_settlement_id')->nullable()->constrained('courier_settlements')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // O7: a link that pays one order.
        Schema::create('sale_payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            // Null: whatever is still owed when the customer opens it.
            $table->decimal('amount', 12, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->foreignId('sale_payment_link_id')->nullable()->after('customer_id')->constrained('sale_payment_links')->nullOnDelete();
        });

        // O8: asking for a review once a parcel is delivered.
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('review_token', 40)->nullable()->unique()->after('return_charge');
            $table->timestamp('review_requested_at')->nullable()->after('review_token');
        });

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('whatsapp_order_id')->constrained()->nullOnDelete();
        });

        // O9: a shop asking us to build its website, talked through on a support ticket.
        Schema::create('website_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('submitted');
            $table->json('requirements');
            $table->foreignId('store_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_url', 255)->nullable();
            $table->string('admin_note', 1000)->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_requests');

        Schema::table('customer_reviews', fn (Blueprint $table) => $table->dropConstrainedForeignId('shipment_id'));

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['review_token']);
            $table->dropColumn(['review_token', 'review_requested_at']);
        });

        Schema::table('customer_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_payment_link_id'));
        Schema::dropIfExists('sale_payment_links');
        Schema::dropIfExists('marketplace_imports');

        Schema::table('store_products', fn (Blueprint $table) => $table->dropColumn(['external_ref', 'inventory_ref']));

        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropIndex(['driver', 'external_domain']);
            $table->dropColumn(['external_domain', 'credentials', 'settings', 'connected_at']);
        });
    }
};
