<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_normalized', 20)->nullable()->after('phone');
            $table->index(['shop_id', 'phone_normalized']);
        });

        DB::table('customers')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($customers) {
            foreach ($customers as $customer) {
                DB::table('customers')->where('id', $customer->id)->update([
                    'phone_normalized' => PhoneNumber::normalize($customer->phone),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'phone_normalized']);
            $table->dropColumn('phone_normalized');
        });
    }
};
