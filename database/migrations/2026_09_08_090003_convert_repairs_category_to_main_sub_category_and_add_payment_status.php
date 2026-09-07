<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('category');
            $table->string('payment_status')->default('paid')->after('total');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        // The old RepairCategory enum used 'phone'/'accessory'. MainCategory's
        // builtin Mobile Phone category uses the slug 'mobile', not 'phone' —
        // remap existing rows so historical repairs stay correctly classified
        // under the new Main Category taxonomy. 'accessory' already matches.
        DB::table('repairs')->where('category', 'phone')->update(['category' => 'mobile']);

        // Every repair before this migration was collected from the customer
        // in full at the time of entry — backfill as fully paid.
        DB::table('repairs')->update(['amount_paid' => DB::raw('total')]);
    }

    public function down(): void
    {
        DB::table('repairs')->where('category', 'mobile')->update(['category' => 'phone']);

        Schema::table('repairs', function (Blueprint $table) {
            $table->dropColumn(['sub_category', 'payment_status', 'amount_paid']);
        });
    }
};
