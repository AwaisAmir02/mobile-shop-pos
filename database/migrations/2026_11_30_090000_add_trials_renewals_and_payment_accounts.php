<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trials, monthly renewals and the accounts customers pay into, built on the
 * manual bank-transfer gateway until a real one is added.
 *
 * Existing shops get auto_billing = false, so nothing about them changes on
 * deploy: they are never locked and keep the add-on flow they had. Only shops
 * that sign up from now on (or that an admin switches over) are billed by
 * BillingCycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Existing plans get 15 days, the owner's example; editable per plan.
            $table->unsignedSmallInteger('trial_days')->default(15)->after('billing_interval');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('auto_billing')->default(false)->after('subscription_start_date');
            $table->timestamp('trial_ends_at')->nullable()->after('auto_billing');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            // [{kind, label, amount, shop_addon_id?}]; null on older invoices, which are one plan line.
            $table->json('items')->nullable()->after('total');
        });

        Schema::table('shop_addons', function (Blueprint $table) {
            // Billed with the plan payment instead of through an invoice of its own.
            $table->boolean('plan_billed')->default(false)->after('subscription_invoice_id');
            // The part-cycle charge from the day it was bought, and the plan invoice that carries it (null = not invoiced yet).
            $table->decimal('first_charge', 12, 2)->nullable()->after('plan_billed');
            $table->foreignId('first_charge_invoice_id')->nullable()->after('first_charge')->constrained('subscription_invoices')->nullOnDelete();
        });

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('provider', 80)->nullable();
            $table->string('account_title', 120);
            $table->string('account_number', 60);
            $table->string('iban', 40)->nullable();
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');

        Schema::table('shop_addons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_charge_invoice_id');
            $table->dropColumn(['plan_billed', 'first_charge']);
        });

        Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropColumn('items'));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['auto_billing', 'trial_ends_at']));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('trial_days'));
    }
};
