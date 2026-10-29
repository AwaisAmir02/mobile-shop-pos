<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->string('status', 20)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
