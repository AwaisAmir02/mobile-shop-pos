<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_loads', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->default(0)->after('amount');
            $table->decimal('discount', 10, 2)->default(0)->after('fee');
            $table->decimal('total', 10, 2)->default(0)->after('discount');
        });

        // Historical loads predate the fee/discount breakdown — their total
        // collected from the customer was simply the amount loaded.
        DB::table('balance_loads')->update(['total' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('balance_loads', function (Blueprint $table) {
            $table->dropColumn(['fee', 'discount', 'total']);
        });
    }
};
