<?php

namespace App\Actions;

use App\Enums\BroadcastDelivery;
use App\Enums\FeatureKey;
use App\Enums\MemberStanding;
use App\Enums\MembershipKind;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\RecordStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Shop;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\WhatsAppClient;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One message (a diet plan, new timings, an offer) to many customers, using
 * the WhatsApp messaging the shop already has, without a new template:
 *
 * WhatsApp only lets a business send its own words, with no approved
 * template, to someone who messaged it in the last 24 hours. So each person
 * either gets it straight from the shop's WhatsApp Business number (in that
 * window, with Customer Messages on and connected), or is listed "to send by
 * hand": a link that opens WhatsApp on the staff member's phone with the
 * message typed in. A new template sent to every shop's WhatsApp account
 * would lift this, and is deliberately not added (coding conventions).
 */
class Broadcasts
{
    public const AUDIENCES = [
        'active_members' => 'Members with a running membership or package',
        'expiring_members' => 'Memberships ending in the next 7 days',
        'expired_members' => 'Memberships that ended in the last 30 days',
        'all_customers' => 'Every customer with a phone number',
        'picked' => 'Customers I choose',
    ];

    public const ATTACHMENT_RULES = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

    /** @return Collection<int, Customer> with a usable phone number */
    public static function recipients(int $shopId, string $audience, array $pickedIds = []): Collection
    {
        $today = now('Asia/Karachi')->toDateString();
        $query = Customer::withoutGlobalScopes()->where('shop_id', $shopId)->whereNotNull('phone')->where('phone', '!=', '');

        $customers = match ($audience) {
            'all_customers' => $query->orderBy('name')->get(),
            'picked' => $query->whereIn('id', array_map('intval', $pickedIds))->orderBy('name')->get(),
            'active_members', 'expiring_members', 'expired_members' => $query->whereIn('id', self::memberIds($shopId, $audience, $today))->orderBy('name')->get(),
            default => throw ValidationException::withMessages(['audience' => 'Choose who gets the message.']),
        };

        return $customers->filter(fn (Customer $c) => PhoneNumber::normalize($c->phone) !== null)->values();
    }

    public static function send(int $shopId, string $audience, string $body, ?UploadedFile $attachment, array $pickedIds, ?int $userId): Broadcast
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write the message.']);
        }

        $people = self::recipients($shopId, $audience, $pickedIds);

        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['audience' => 'Nobody in that list has a phone number.']);
        }

        $broadcast = new Broadcast(['audience' => $audience, 'body' => mb_substr($body, 0, 4000), 'user_id' => $userId]);
        $broadcast->shop_id = $shopId;

        if ($attachment !== null) {
            $broadcast->attachment_path = $attachment->store('broadcasts/'.$shopId, 'local');
            $broadcast->attachment_name = mb_substr($attachment->getClientOriginalName(), 0, 150);
        }

        $broadcast->save();

        $shop = Shop::findOrFail($shopId);
        $connection = $shop->{FeatureKey::WhatsAppMessaging->column()} ? CustomerMessenger::whatsappConnection($shopId) : null;
        $messenger = app(CustomerMessenger::class);
        $mediaId = null;

        foreach ($people as $customer) {
            $contact = $connection ? CustomerMessenger::contactForCustomer($customer, $connection) : null;
            $delivery = BroadcastDelivery::ByHand;
            $error = null;

            if ($contact !== null && $contact->isInServiceWindow()) {
                $notification = $messenger->deliver($connection, $contact, NotificationType::Broadcast, $broadcast, $broadcast->body, [], 'broadcast', [], $userId);
                $delivery = $notification->status === NotificationStatus::Sent ? BroadcastDelivery::Sent : BroadcastDelivery::Failed;
                $error = $notification->error;

                if ($delivery === BroadcastDelivery::Sent && $broadcast->attachment_path !== null) {
                    try {
                        $client = WhatsAppClient::for($connection);
                        $mediaId ??= $client->uploadMedia(Storage::disk('local')->get($broadcast->attachment_path), $broadcast->attachment_name, Storage::disk('local')->mimeType($broadcast->attachment_path) ?: 'application/pdf');
                        $client->sendDocument($contact->wa_id, $mediaId, $broadcast->attachment_name);
                    } catch (Throwable $e) {
                        $error = mb_substr('Message sent, file not: '.$e->getMessage(), 0, 500);
                    }
                }
            }

            BroadcastRecipient::create(['shop_id' => $shopId, 'broadcast_id' => $broadcast->id, 'customer_id' => $customer->id, 'name' => mb_substr($customer->name, 0, 100), 'phone' => mb_substr((string) $customer->phone, 0, 20), 'delivery' => $delivery, 'error' => $error]);
        }

        return $broadcast->load('recipients');
    }

    /** Opens WhatsApp on staff's own phone with the message typed in, and notes that it was opened. */
    public static function openByHand(BroadcastRecipient $recipient, Broadcast $broadcast): ?string
    {
        $number = PhoneNumber::normalize($recipient->phone);

        if ($number === null) {
            return null;
        }

        if (in_array($recipient->delivery, [BroadcastDelivery::ByHand, BroadcastDelivery::Failed], true)) {
            $recipient->update(['delivery' => BroadcastDelivery::OpenedByHand]);
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($broadcast->body);
    }

    /** @return list<int> */
    protected static function memberIds(int $shopId, string $audience, string $today): array
    {
        $memberships = Membership::withoutGlobalScopes()->with('freezes')->where('shop_id', $shopId)->where('status', RecordStatus::Active->value)->get();
        $byCustomer = $memberships->groupBy('customer_id');
        $ids = [];

        foreach ($byCustomer as $customerId => $rows) {
            $customer = new Customer;
            $customer->id = (int) $customerId;
            $standing = Memberships::standing($customer, $today);
            $running = in_array($standing['standing'], [MemberStanding::Active, MemberStanding::Frozen], true);
            $endsOn = $standing['membership']?->ends_on?->toDateString();

            $match = match ($audience) {
                'active_members' => $running,
                'expiring_members' => $running && $standing['membership']->kind === MembershipKind::Time && $endsOn !== null && $endsOn <= now('Asia/Karachi')->addDays(7)->toDateString(),
                'expired_members' => ! $running && $endsOn !== null && $endsOn >= now('Asia/Karachi')->subDays(30)->toDateString(),
                default => false,
            };

            if ($match) {
                $ids[] = (int) $customerId;
            }
        }

        return $ids;
    }
}
