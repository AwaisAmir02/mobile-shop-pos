<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services group (repair workshop, salon, spa, gym, personal training).
 *
 * Existing rows read exactly as before: every sale line is a product
 * (line_kind default), every shop has no linked trades, and the service menu
 * columns are blank, so the mobile shop's own Services module is unchanged.
 * Everything else is new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A salon that is also a spa: the related trades it runs besides its own category.
        Schema::table('shops', function (Blueprint $table) {
            $table->json('business_addons')->nullable()->after('business_category');
        });

        // The service menu: the existing catalogue gains a price, a time and a class size.
        Schema::table('service_items', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('name');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('price');
            $table->unsignedSmallInteger('capacity')->default(1)->after('duration_minutes');
            $table->boolean('is_active')->default(true)->after('capacity');
        });

        // Staff who take bookings show as columns on the day calendar.
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('takes_appointments')->default(false)->after('is_active');
        });

        // A member's number: the PIN they are enrolled under on a fingerprint device.
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('member_no')->nullable()->after('phone');
            $table->unique(['shop_id', 'member_no']);
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->decimal('price', 10, 2);
            // By time: so many days or months. By sessions: how many, and how long they last (optional).
            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->string('duration_unit', 10)->nullable();
            $table->unsignedSmallInteger('sessions')->nullable();
            // Which services a package's sessions can be spent on; null means any.
            $table->json('service_item_ids')->nullable();
            $table->unsignedSmallInteger('max_freeze_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Sale lines that are not goods (App\Actions\ServiceLines).
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('line_kind', 20)->default('product')->after('product_type');
            $table->foreignId('service_item_id')->nullable()->after('line_kind')->constrained('service_items')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->after('service_item_id')->constrained('employees')->nullOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->after('performed_by')->constrained('membership_plans')->nullOnDelete();
            $table->unsignedBigInteger('membership_id')->nullable()->after('membership_plan_id');
            $table->index(['shop_id', 'line_kind']);
            $table->index('membership_id');
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->string('plan_name', 100);
            $table->string('kind', 20);
            $table->decimal('price', 10, 2);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('sessions_total')->nullable();
            $table->json('service_item_ids')->nullable();
            $table->unsignedSmallInteger('max_freeze_days')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'ends_on']);
        });

        Schema::create('membership_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('walk_in')->default(false);
            $table->date('appointment_date');
            $table->dateTime('starts_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->string('status', 20)->default('booked');
            $table->string('notes', 500)->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'appointment_date']);
            $table->index(['employee_id', 'starts_at']);
        });

        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_item_id')->nullable()->constrained('service_items')->nullOnDelete();
            $table->string('name', 255);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->timestamps();
        });

        Schema::create('job_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('received');
            $table->date('received_on');
            $table->date('promised_on')->nullable();
            $table->boolean('site_visit')->default(false);
            $table->string('site_address', 300)->nullable();
            $table->decimal('visit_charge', 10, 2)->default(0);
            $table->decimal('estimate_total', 10, 2)->nullable();
            $table->timestamp('estimate_approved_at')->nullable();
            $table->string('estimate_approved_via', 30)->nullable();
            $table->unsignedSmallInteger('warranty_days')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        Schema::create('job_card_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label', 150);
            $table->json('details')->nullable();
            $table->string('problem', 1000)->nullable();
            $table->string('findings', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('job_card_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_item_id')->nullable()->constrained('job_card_items')->nullOnDelete();
            $table->string('kind', 20);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_item_id')->nullable()->constrained('service_items')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Money a customer paid ahead on a booking or a job card: held until its bill, refunded or kept.
        Schema::create('customer_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('received_on');
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained('shop_accounts')->nullOnDelete();
            $table->decimal('refunded', 10, 2)->default(0);
            $table->date('refunded_on')->nullable();
            $table->string('refund_method', 20)->nullable();
            $table->foreignId('refund_account_id')->nullable()->constrained('shop_accounts')->nullOnDelete();
            $table->decimal('forfeited', 10, 2)->default(0);
            $table->date('forfeited_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
        });

        Schema::create('gift_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);
            $table->decimal('amount', 10, 2);
            $table->foreignId('buyer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('buyer_name', 100)->nullable();
            $table->string('recipient_name', 100)->nullable();
            $table->date('sold_on');
            $table->date('expires_on')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained('shop_accounts')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        // Which voucher or deposit a "gift_voucher" / "deposit" sale payment drew on.
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('gift_voucher_id')->nullable()->constrained('gift_vouchers')->nullOnDelete();
            $table->foreignId('customer_deposit_id')->nullable()->constrained('customer_deposits')->nullOnDelete();
        });

        Schema::create('staff_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // Null: everyone without a rule of their own for this.
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('target', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('kind', 20);
            $table->decimal('value', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The commission a payslip was generated with: once paid, later changes come back as an adjustment.
        Schema::create('staff_earning_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->decimal('computed', 12, 2);
            $table->decimal('adjustment', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('serial_number', 40)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('remove_expired')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('push_version', 20)->nullable();
            $table->string('firmware', 60)->nullable();
            $table->string('att_stamp', 30)->nullable();
            $table->string('op_stamp', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('biometric_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->text('command');
            $table->string('status', 20)->default('queued');
            $table->integer('return_code')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['biometric_device_id', 'status']);
        });

        // Fingerprint templates a device sent up, so a member removed for an expired membership can be put back.
        Schema::create('biometric_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pin');
            $table->unsignedTinyInteger('finger');
            $table->text('template');
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'pin', 'finger']);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('checked_in_at');
            $table->string('source', 20);
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('device_pin')->nullable();
            $table->string('standing', 20);
            $table->decimal('dues', 12, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'checked_in_at']);
            $table->unique(['biometric_device_id', 'device_pin', 'checked_in_at'], 'check_ins_device_punch_unique');
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('body', 2000);
            $table->boolean('pinned')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('audience', 30);
            $table->text('body');
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_name', 150)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('phone', 20);
            $table->string('delivery', 20);
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_voucher_id');
            $table->dropConstrainedForeignId('customer_deposit_id');
        });

        foreach (['broadcast_recipients', 'broadcasts', 'customer_notes', 'check_ins', 'biometric_templates', 'biometric_commands', 'biometric_devices', 'staff_earning_locks', 'staff_commission_rules', 'gift_vouchers', 'customer_deposits', 'job_card_lines', 'job_card_items', 'job_cards', 'appointment_services', 'appointments', 'membership_freezes', 'memberships'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'line_kind']);
            $table->dropIndex(['membership_id']);
            $table->dropConstrainedForeignId('membership_plan_id');
            $table->dropConstrainedForeignId('performed_by');
            $table->dropConstrainedForeignId('service_item_id');
            $table->dropColumn(['line_kind', 'membership_id']);
        });

        Schema::dropIfExists('membership_plans');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'member_no']);
            $table->dropColumn('member_no');
        });
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('takes_appointments'));
        Schema::table('service_items', fn (Blueprint $table) => $table->dropColumn(['price', 'duration_minutes', 'capacity', 'is_active']));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('business_addons'));
    }
};
