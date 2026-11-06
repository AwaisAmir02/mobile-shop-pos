<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry ledger. Purely additive: it reads the existing tables and
 * writes only these, so every balance a shop already sees is untouched. Amounts
 * are whole paise, debit positive, credit negative. Off for every shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('ledger_enabled')->default(false)->after('payroll_notice_dismissed_at');
            $table->timestamp('ledger_started_at')->nullable()->after('ledger_enabled');
            $table->timestamp('ledger_frozen_at')->nullable()->after('ledger_started_at');
            $table->unsignedInteger('ledger_epoch')->default(0)->after('ledger_frozen_at');
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 10);
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('epoch');
            $table->date('entry_date');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'epoch', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_paise');
            $table->string('party_type', 20)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // What the document asked for, when a pre-start change was moved to today or Prior Period.
            $table->string('origin_account_code', 20);
            $table->date('origin_date');

            $table->index(['shop_id', 'ledger_account_id']);
            $table->index(['shop_id', 'party_type', 'party_id']);
        });

        Schema::create('ledger_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['source_type', 'source_id']);
            $table->index(['shop_id', 'attempts']);
        });

        Schema::create('ledger_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('epoch');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->json('lines');

            $table->unique(['shop_id', 'epoch', 'source_type', 'source_id'], 'ledger_baselines_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_baselines');
        Schema::dropIfExists('ledger_outbox');
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['ledger_enabled', 'ledger_started_at', 'ledger_frozen_at', 'ledger_epoch']));
    }
};
