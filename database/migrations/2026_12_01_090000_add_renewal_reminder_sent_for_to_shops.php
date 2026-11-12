<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The renewal date the "ending soon" email was last sent for, so
 * billing:renewal-reminders emails each renewal once. Null = never sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->date('renewal_reminder_sent_for')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('renewal_reminder_sent_for'));
    }
};
