<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup happens in steps. Changes are noted from ledger_started_at, but nothing
 * posts until ledger_ready_at, when the opening entry is in place. A setup that's
 * interrupted can simply run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('ledger_ready_at')->nullable()->after('ledger_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('ledger_ready_at'));
    }
};
