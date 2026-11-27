<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services group P2: QR check-in, staff attendance, and automatic WhatsApp
 * reminders (bookings, renewals, service due). Every new column is blank or
 * defaulted, so existing rows read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The code on a member's card, scanned at the desk (QR check-in).
        Schema::table('customers', function (Blueprint $table) {
            $table->string('checkin_token', 40)->nullable()->unique()->after('member_no');
        });

        // Staff clock in on the same devices (fingerprint PIN) and QR badges as members.
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedInteger('attendance_pin')->nullable()->after('takes_appointments');
            $table->string('badge_token', 40)->nullable()->unique()->after('attendance_pin');
            $table->unique(['shop_id', 'attendance_pin']);
        });

        Schema::create('staff_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('punched_at');
            $table->string('source', 20);
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('device_pin')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'punched_at']);
            $table->index(['employee_id', 'punched_at']);
            $table->unique(['biometric_device_id', 'device_pin', 'punched_at'], 'staff_punches_device_punch_unique');
        });

        // Automatic reminders: each goes out once.
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('sale_id');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->timestamp('renewal_reminded_at')->nullable()->after('cancelled_at');
        });

        // A workshop's next service for the unit (bike every 3 months, AC every 6).
        Schema::table('job_cards', function (Blueprint $table) {
            $table->unsignedSmallInteger('next_service_days')->nullable()->after('warranty_until');
            $table->date('service_due_on')->nullable()->after('next_service_days');
            $table->timestamp('service_reminded_at')->nullable()->after('service_due_on');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('service_reminders_enabled')->default(true)->after('business_addons');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('service_reminders_enabled'));
        Schema::table('job_cards', fn (Blueprint $table) => $table->dropColumn(['next_service_days', 'service_due_on', 'service_reminded_at']));
        Schema::table('memberships', fn (Blueprint $table) => $table->dropColumn('renewal_reminded_at'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
        Schema::dropIfExists('staff_punches');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'attendance_pin']);
            $table->dropUnique(['badge_token']);
            $table->dropColumn(['attendance_pin', 'badge_token']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['checkin_token']);
            $table->dropColumn('checkin_token');
        });
    }
};
