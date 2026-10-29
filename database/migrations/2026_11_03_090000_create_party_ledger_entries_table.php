<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manually entered opening balances and adjustments for the Party Ledger.
     * These form their own separate balance on a customer's statement and are
     * never merged into the Sales or Udhaar balances.
     */
    public function up(): void
    {
        Schema::create('party_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('direction');
            $table->decimal('amount', 12, 2);
            $table->date('entry_date');
            $table->string('note', 500);
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'customer_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_ledger_entries');
    }
};
