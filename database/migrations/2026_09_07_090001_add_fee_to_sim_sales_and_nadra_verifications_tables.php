<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sim_sales', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->default(0)->after('amount');
        });

        Schema::table('nadra_verifications', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('sim_sales', function (Blueprint $table) {
            $table->dropColumn('fee');
        });

        Schema::table('nadra_verifications', function (Blueprint $table) {
            $table->dropColumn('fee');
        });
    }
};
