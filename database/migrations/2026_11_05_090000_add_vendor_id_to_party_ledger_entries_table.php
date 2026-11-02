<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opening balances and adjustments can now belong to a vendor instead of
     * a customer. Exactly one of the two is set per entry, enforced by
     * PartyLedgerEntry (no database CHECK constraint).
     */
    public function up(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreignId('vendor_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();

            $table->index(['shop_id', 'vendor_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'vendor_id', 'entry_date']);
            $table->dropConstrainedForeignId('vendor_id');
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
