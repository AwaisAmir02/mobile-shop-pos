<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_loads', function (Blueprint $table) {
            $table->string('load_type')->default('balance')->after('network');
        });
    }

    public function down(): void
    {
        Schema::table('balance_loads', function (Blueprint $table) {
            $table->dropColumn('load_type');
        });
    }
};
