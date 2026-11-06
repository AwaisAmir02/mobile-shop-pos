<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll sits beside the app rather than inside it: a paid payslip adds one
 * ordinary Expense row (expenses.payslip_id), which is all the Dashboard's Net
 * ever needs to read. Advances are money owed back, so they have their own
 * ledger and never touch expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('designation', 100)->nullable();
            $table->string('salary_type')->default('fixed');
            $table->decimal('rate', 12, 2);
            $table->date('joined_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });

        Schema::create('deduction_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('reduces_cost')->default(true);
            $table->decimal('default_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period');
            $table->string('employee_name');
            $table->string('designation', 100)->nullable();
            $table->string('salary_type');
            $table->decimal('rate', 12, 2);
            $table->decimal('days', 5, 2)->nullable();
            $table->decimal('base_pay', 12, 2)->default(0);
            $table->decimal('extra_earnings', 12, 2)->default(0);
            $table->decimal('cost_deductions', 12, 2)->default(0);
            $table->decimal('held_deductions', 12, 2)->default(0);
            $table->decimal('advance_recovery', 12, 2)->default(0);
            $table->decimal('salary_cost', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->string('status')->default('draft');
            // 1 while active, NULL once cancelled: at most one active payslip per person per period.
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->date('paid_on')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'period', 'active_marker'], 'payslips_one_active_per_period');
            $table->index(['shop_id', 'period']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('label', 100);
            $table->decimal('amount', 12, 2);
            $table->foreignId('deduction_type_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('reduces_cost')->default(false);
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->string('note', 255)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'transaction_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('payslip_id')->nullable()->unique()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('payroll_enabled')->default(false)->after('purchase_orders_enabled');
            $table->timestamp('payroll_notice_dismissed_at')->nullable()->after('payroll_enabled');
        });

        // Included in Growth and Commerce, and on for the shops already on them.
        foreach (DB::table('plans')->whereIn('name', ['Growth', 'Commerce'])->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode(['payroll' => true] + $flags)]);
            DB::table('shops')->where('plan_id', $plan->id)->update(['payroll_enabled' => true]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            unset($flags['payroll']);
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => $flags === [] ? null : json_encode($flags)]);
        }

        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['payroll_enabled', 'payroll_notice_dismissed_at']));
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payslip_id');
        });
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('deduction_types');
        Schema::dropIfExists('employees');
    }
};
