<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_category_options', function (Blueprint $table) {
            $table->foreignId('main_category_id')->nullable()->after('shop_id')->constrained()->nullOnDelete();
        });

        // Every shop that already has Accessory Category (now Sub-Category)
        // rows needs the two builtin Main Categories to exist so those rows
        // can be attached to "Accessory" without breaking their existing
        // in-use-on-a-product assignment. Shops created after this migration
        // get both builtins lazily via MainCategory::ensureDefaultsExist(),
        // matching how every other seeded list in this app already works.
        $now = now();

        DB::table('accessory_category_options')
            ->select('shop_id')
            ->distinct()
            ->orderBy('shop_id')
            ->chunk(200, function ($rows) use ($now) {
                foreach ($rows as $row) {
                    $accessoryId = DB::table('main_categories')
                        ->where('shop_id', $row->shop_id)
                        ->where('slug', 'accessory')
                        ->value('id');

                    if (! $accessoryId) {
                        $accessoryId = DB::table('main_categories')->insertGetId([
                            'shop_id' => $row->shop_id,
                            'name' => 'Accessory',
                            'slug' => 'accessory',
                            'is_builtin' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $hasMobile = DB::table('main_categories')
                        ->where('shop_id', $row->shop_id)
                        ->where('slug', 'mobile')
                        ->exists();

                    if (! $hasMobile) {
                        DB::table('main_categories')->insert([
                            'shop_id' => $row->shop_id,
                            'name' => 'Mobile Phone',
                            'slug' => 'mobile',
                            'is_builtin' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('accessory_category_options')
                        ->where('shop_id', $row->shop_id)
                        ->update(['main_category_id' => $accessoryId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('accessory_category_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_category_id');
        });
    }
};
