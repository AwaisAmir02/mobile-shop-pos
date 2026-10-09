<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('revisionable_type');
            $table->unsignedBigInteger('revisionable_id');
            $table->string('action');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->index(['revisionable_type', 'revisionable_id', 'created_at'], 'module_revisions_revisionable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_revisions');
    }
};
