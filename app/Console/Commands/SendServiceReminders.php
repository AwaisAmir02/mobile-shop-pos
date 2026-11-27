<?php

namespace App\Console\Commands;

use App\Actions\ServiceReminders;
use App\Services\WhatsApp\CustomerNotifications;
use Illuminate\Console\Command;

class SendServiceReminders extends Command
{
    protected $signature = 'services:reminders {--limit=200}';

    protected $description = 'Send due WhatsApp reminders for bookings, membership renewals and service due (service businesses)';

    public function handle(CustomerNotifications $notifications): int
    {
        $sent = ServiceReminders::sendDue($notifications, (int) $this->option('limit'));

        $this->info("Sent {$sent['appointments']} booking, {$sent['renewals']} renewal and {$sent['service']} service-due reminder(s).");

        return self::SUCCESS;
    }
}
