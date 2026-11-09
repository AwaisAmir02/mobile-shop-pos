<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D13: support tickets. A shop user opens a ticket from any screen; the
 * platform admin answers in the same thread. Images are stored on the
 * private disk and served only through routes that check the ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // Opener. Kept nullable so a removed team member's tickets stay readable.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open');

            // The screen the user was on when they opened the ticket.
            $table->string('screen_route', 120)->nullable();
            $table->string('screen_url', 500)->nullable();
            $table->string('screen_title', 150)->nullable();
            $table->string('device', 100)->nullable();

            // Read/unread bookkeeping for both sides.
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_shop_message_at')->nullable();
            $table->timestamp('last_admin_message_at')->nullable();
            $table->timestamp('shop_read_at')->nullable();
            $table->timestamp('admin_read_at')->nullable();
            $table->timestamp('opener_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'last_message_at']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_admin')->default(false);
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
