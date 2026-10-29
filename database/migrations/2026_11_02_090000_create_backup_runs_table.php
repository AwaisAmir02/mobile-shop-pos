<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20);
            $table->string('trigger', 20);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dumper', 20)->nullable();
            $table->string('archive_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('local_path')->nullable();
            $table->string('remote_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
