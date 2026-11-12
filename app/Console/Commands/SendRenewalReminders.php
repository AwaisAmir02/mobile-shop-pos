<?php

namespace App\Console\Commands;

use App\Enums\BillingPhase;
use App\Enums\SubscriptionStatus;
use App\Mail\RenewalReminderMail;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Services\Billing\BillingCycle;
use App\Support\ShopOwnerEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the owner when the "ending soon" banner would show
 * (x-billing.pending-banner): EndingSoon phase and no payment sent yet.
 * Once per renewal date, remembered in shops.renewal_reminder_sent_for.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'billing:renewal-reminders';

    protected $description = 'Email shop owners whose paid period is ending soon and who haven’t paid yet';

    public function handle(BillingCycle $cycle): int
    {
        $sent = 0;

        Shop::query()
            ->where('auto_billing', true)
            ->whereNotNull('plan_id')
            ->where('subscription_status', SubscriptionStatus::Active->value)
            ->with('plan')
            ->chunkById(200, function ($shops) use ($cycle, &$sent) {
                foreach ($shops as $shop) {
                    if ($cycle->phase($shop) !== BillingPhase::EndingSoon || SubscriptionPayment::openFor($shop->id)?->submitted_at) {
                        continue;
                    }

                    $renewsAt = $cycle->renewsAt($shop);

                    if ($shop->renewal_reminder_sent_for === $renewsAt->toDateString()) {
                        continue;
                    }

                    // Marked even with no address, so a shop without one isn't re-checked every hour for nothing.
                    Shop::whereKey($shop->id)->update(['renewal_reminder_sent_for' => $renewsAt->toDateString()]);

                    if ($email = ShopOwnerEmail::for($shop)) {
                        Mail::to($email)->queue(new RenewalReminderMail($shop, $renewsAt, $cycle->daysLeft($shop)));
                        $sent++;
                    }
                }
            });

        $this->info("Queued {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }
}
