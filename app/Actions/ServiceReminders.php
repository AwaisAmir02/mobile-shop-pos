<?php

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Enums\JobCardStatus;
use App\Enums\MembershipKind;
use App\Enums\MemberStanding;
use App\Enums\RecordStatus;
use App\Enums\ServiceTradeTool;
use App\Models\Appointment;
use App\Models\JobCard;
use App\Models\Membership;
use App\Models\Shop;
use App\Services\WhatsApp\CustomerNotifications;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Automatic WhatsApp reminders for service businesses, sent once each by
 * services:reminders through the shop's own WhatsApp Business number:
 *
 * - a booking starting in the next 24 hours (at least an hour away),
 * - a membership ending within 3 days that hasn't been renewed,
 * - a unit a workshop serviced that is due again (job_cards.service_due_on).
 *
 * Only for shops with Customer Messages on and connected, whose trade uses
 * the tool, and that haven't switched reminders off. Each is marked before
 * sending, so a failure is logged in Customer Messages and never retried in a
 * loop; the screens keep a "Remind" link to send it by hand at any time.
 */
class ServiceReminders
{
    public const RENEWAL_DAYS = 3;

    /** @return array{appointments: int, renewals: int, service: int} sent */
    public static function sendDue(CustomerNotifications $notifications, int $limit = 200): array
    {
        $sent = ['appointments' => 0, 'renewals' => 0, 'service' => 0];

        foreach (self::dueAppointments($limit) as $appointment) {
            $appointment->forceFill(['reminded_at' => now()])->save();
            $sent['appointments'] += self::attempt(fn () => $notifications->appointmentReminder($appointment));
        }

        foreach (self::dueRenewals($limit) as $membership) {
            $membership->forceFill(['renewal_reminded_at' => now()])->save();
            $sent['renewals'] += self::attempt(fn () => $notifications->renewalReminder($membership));
        }

        foreach (self::dueServices($limit) as $job) {
            $job->forceFill(['service_reminded_at' => now()])->save();
            $sent['service'] += self::attempt(fn () => $notifications->serviceDue($job));
        }

        return $sent;
    }

    /** @return Collection<int, Appointment> */
    public static function dueAppointments(int $limit = 200): Collection
    {
        return Appointment::withoutGlobalScopes()
            ->with(['services', 'customer'])
            ->whereIn('shop_id', self::shopIds(ServiceTradeTool::Appointments))
            ->where('status', AppointmentStatus::Booked->value)
            ->whereNotNull('customer_id')
            ->whereNull('reminded_at')
            ->whereBetween('starts_at', [now()->addHour(), now()->addDay()])
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Membership> running time memberships ending soon, not yet renewed */
    public static function dueRenewals(int $limit = 200): Collection
    {
        $today = now('Asia/Karachi')->toDateString();

        return Membership::withoutGlobalScopes()
            ->with('customer')
            ->whereIn('shop_id', self::shopIds(ServiceTradeTool::Memberships))
            ->where('status', RecordStatus::Active->value)
            ->where('kind', MembershipKind::Time->value)
            ->whereNull('renewal_reminded_at')
            ->whereBetween('ends_on', [$today, now('Asia/Karachi')->addDays(self::RENEWAL_DAYS)->toDateString()])
            ->orderBy('ends_on')
            ->limit($limit)
            ->get()
            // Renewed already: a later membership carries on after this one.
            ->reject(fn (Membership $m) => Membership::withoutGlobalScopes()->where('customer_id', $m->customer_id)->where('status', RecordStatus::Active->value)->where('starts_on', '>', $m->ends_on)->exists())
            ->values();
    }

    /** @return Collection<int, JobCard> */
    public static function dueServices(int $limit = 200): Collection
    {
        return JobCard::withoutGlobalScopes()
            ->with(['customer', 'items'])
            ->whereIn('shop_id', self::shopIds(ServiceTradeTool::JobCards))
            ->where('status', JobCardStatus::Delivered->value)
            ->whereNull('service_reminded_at')
            ->whereNotNull('service_due_on')
            ->whereBetween('service_due_on', [now('Asia/Karachi')->subDays(30)->toDateString(), now('Asia/Karachi')->toDateString()])
            ->orderBy('service_due_on')
            ->limit($limit)
            ->get();
    }

    /** The renewal text to send by hand (a wa.me link). */
    public static function renewalText(Membership $membership, string $shopName): string
    {
        return 'Assalam o Alaikum '.$membership->customer?->name.', your '.$membership->plan_name.' at '.$shopName
            .($membership->ends_on ? ' ends on '.$membership->ends_on->format('d M Y') : ' has ended').'. Renew at the counter to keep it running. Thank you!';
    }

    /** The service-due text to send by hand. */
    public static function serviceDueText(JobCard $job, string $shopName): string
    {
        $job->loadMissing('items');

        return 'Assalam o Alaikum '.$job->customer?->name.', your '.$job->unitsLabel().' is due for its next service at '.$shopName
            .($job->service_due_on ? ' (due '.$job->service_due_on->format('d M Y').')' : '').'. Reply to book a time. Thank you!';
    }

    /** @return list<int> */
    protected static function shopIds(ServiceTradeTool $tool): array
    {
        return Shop::query()
            ->where('whatsapp_messaging_enabled', true)
            ->where('service_reminders_enabled', true)
            ->whereNotNull('business_category')
            ->get(['id', 'business_category', 'business_addons'])
            ->filter(fn (Shop $shop) => $shop->usesTool($tool))
            ->pluck('id')
            ->all();
    }

    protected static function attempt(callable $send): int
    {
        try {
            $notification = $send();

            return $notification->status === \App\Enums\NotificationStatus::Sent ? 1 : 0;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
