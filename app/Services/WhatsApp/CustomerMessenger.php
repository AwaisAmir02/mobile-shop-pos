<?php

namespace App\Services\WhatsApp;

use App\Enums\ChannelProvider;
use App\Enums\LimitKey;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Services\Billing\Entitlements;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CustomerMessenger
{
    public function __construct(protected ?WhatsAppClient $client = null)
    {
    }

    public static function contactForCustomer(Customer $customer, ShopChannelConnection $connection): ?WhatsAppContact
    {
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $customer->shop_id)
            ->where('customer_id', $customer->id)
            ->latest('last_inbound_at')
            ->first();

        if ($contact !== null) {
            return $contact;
        }

        $waId = PhoneNumber::normalize($customer->phone);

        if ($waId === null) {
            return null;
        }

        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->firstOrNew([
            'shop_id' => $customer->shop_id,
            'wa_id' => $waId,
        ]);

        $contact->customer_id ??= $customer->id;
        $contact->shop_channel_connection_id ??= $connection->id;
        $contact->profile_name ??= $customer->name;
        $contact->save();

        return $contact;
    }

    public function deliver(
        ShopChannelConnection $connection,
        WhatsAppContact $contact,
        NotificationType $type,
        ?Model $notifiable,
        string $sessionText,
        array $sessionButtons,
        string $templateKey,
        array $templateParams,
        ?int $userId = null,
    ): CustomerNotification {
        $notification = new CustomerNotification([
            'shop_id' => $connection->shop_id,
            'whatsapp_contact_id' => $contact->id,
            'customer_id' => $contact->customer_id,
            'type' => $type,
            'notifiable_type' => $notifiable?->getMorphClass(),
            'notifiable_id' => $notifiable?->getKey(),
            'template_key' => $templateKey,
            'body' => $sessionText,
            'sent_by' => $userId,
        ]);

        $client = $this->client ?? WhatsAppClient::for($connection);

        try {
            if ($type->pausesAtMessageLimit() && ! app(Entitlements::class)->allows(Shop::findOrFail($connection->shop_id), LimitKey::WhatsAppMessagesPerMonth)) {
                throw new \RuntimeException('Monthly WhatsApp message limit reached — automatic messages are paused until next month or until you add more messages.');
            }

            if ($contact->isInServiceWindow()) {
                $notification->delivery_mode = 'session';
                $notification->wamid = $sessionButtons === []
                    ? $client->sendText($contact->wa_id, $sessionText)
                    : $client->sendButtons($contact->wa_id, $sessionText, $sessionButtons);
            } else {
                $template = TemplateManager::approved($connection, $templateKey, WhatsAppLanguage::for($contact));

                if ($template === null) {
                    throw new \RuntimeException('The customer has not messaged in the last 24 hours and the "'.$templateKey.'" WhatsApp template is not approved yet.');
                }

                $definition = TemplateLibrary::definition($templateKey);
                $notification->delivery_mode = 'template';
                $notification->wamid = $client->sendTemplate(
                    $contact->wa_id,
                    $template->name,
                    $template->language,
                    $templateParams,
                    $definition['button_payloads'] ?? [],
                );
            }

            $notification->status = NotificationStatus::Sent;
            $notification->sent_at = now();
        } catch (Throwable $e) {
            $notification->status = NotificationStatus::Failed;
            $notification->error = mb_substr($e->getMessage(), 0, 500);
        }

        $notification->save();

        return $notification;
    }

    public static function whatsappConnection(int $shopId): ?ShopChannelConnection
    {
        return ShopChannelConnection::activeFor($shopId, ChannelProvider::WhatsApp);
    }
}
