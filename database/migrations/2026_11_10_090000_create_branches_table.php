<?php

use App\Support\BranchBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-branch. Every shop gets one Main branch and every
     * existing transactional row is tagged to it. branch_id stays nullable
     * at the database level (the app always fills it on create) and is
     * nulled rather than blocking when a shop is deleted, since branches
     * themselves are only ever archived.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_main')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        foreach ([...BranchBackfill::TRANSACTION_TABLES, 'shop_accounts', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->after('shop_id')->constrained()->nullOnDelete();
            });
        }

        DB::statement(BranchBackfill::MAIN_BRANCHES_SQL);

        foreach (BranchBackfill::TRANSACTION_TABLES as $name) {
            DB::statement(BranchBackfill::tagSql($name));
        }

        DB::statement(BranchBackfill::PLAN_LIMITS_SQL);
    }

    public function down(): void
    {
        foreach ([...BranchBackfill::TRANSACTION_TABLES, 'shop_accounts', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }

        Schema::dropIfExists('branches');
    }
};
