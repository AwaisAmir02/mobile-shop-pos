<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('branding_enabled')->default(false)->after('barcode_enabled');
            $table->string('invoice_display_name')->nullable()->after('branding_enabled');
            $table->string('logo_path')->nullable()->after('invoice_display_name');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['branding_enabled', 'invoice_display_name', 'logo_path']);
        });
    }
};
