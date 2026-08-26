<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('is_super_admin');
            $table->foreignId('role_id')->nullable()->after('shop_id')->constrained()->nullOnDelete();
        });

        DB::table('users')
            ->whereNotNull('shop_id')
            ->update(['is_owner' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('is_owner');
        });
    }
};
