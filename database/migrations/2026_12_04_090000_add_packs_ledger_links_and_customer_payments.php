<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiryana tools.
 *
 * Packs: a product sold by the piece can also have a pack (carton, dozen,
 * box…) of a fixed number of pieces, with an optional price per pack. Stock is
 * always counted in pieces; a stock-in keeps how many packs it was bought as.
 *
 * Ledger links: a private token per customer for their own read-only ledger
 * page, made when first needed and replaceable.
 *
 * Customer payments: the shop's own payment-gateway account (JazzCash) and
 * each payment a customer starts from their ledger page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('pack_name', 20)->nullable()->after('warranty_type');
            $table->unsignedInteger('pack_size')->nullable()->after('pack_name');
            $table->decimal('pack_price', 12, 2)->nullable()->after('pack_size');
        });

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->string('pack_name', 20)->nullable()->after('unit');
            $table->unsignedInteger('pack_size')->nullable()->after('pack_name');
            $table->unsignedInteger('packs')->nullable()->after('pack_size');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('ledger_token', 48)->nullable()->unique()->after('address');
        });

        Schema::create('payment_gateway_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->text('credentials')->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('connected');
            $table->timestamp('last_paid_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'gateway']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_gateway_connection_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 20);
            $table->string('reference', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->string('response_code', 20)->nullable();
            $table->string('response_message', 255)->nullable();
            $table->string('gateway_reference', 100)->nullable();
            $table->json('applied_to')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('payment_gateway_connections');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('ledger_token'));
        Schema::table('stock_ins', fn (Blueprint $table) => $table->dropColumn(['pack_name', 'pack_size', 'packs']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['pack_name', 'pack_size', 'pack_price']));
    }
};
