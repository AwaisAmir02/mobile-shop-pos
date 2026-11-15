<?php

namespace App\Services\WhatsApp;

use App\Enums\FulfillmentStatus;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Product;
use App\Models\Repair;
use App\Models\RestockSubscription;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Support\CustomerDuesStatement;
use App\Models\PaymentGatewayConnection;
use App\Services\Sms\PlivoSms;
use App\Support\PhoneNumber;
use RuntimeException;

class CustomerNotifications
{
    public const REMINDER_COOLDOWN_HOURS = 72;

    public function __construct(protected CustomerMessenger $messenger)
    {
    }

    public static function money(float $amount): string
    {
        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    public function orderFulfillment(WhatsAppOrder $order, FulfillmentStatus $status, ?int $userId): CustomerNotification
    {
        $shop = Shop::findOrFail($order->shop_id);
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->findOrFail($order->whatsapp_contact_id);
        $connection = $this->connectionFor($shop, NotificationType::OrderUpdate);

        $sentence = WhatsAppLanguage::t($contact, $status->messageKey(), ['order' => $order->orderNumber(), 'shop' => $shop->invoiceDisplayName()]);

        return $this->messenger->deliver(
            $connection, $contact, NotificationType::OrderUpdate, $order,
            $sentence, [],
            'order_update', [$order->orderNumber(), $shop->invoiceDisplayName(), $sentence],
            $userId,
        );
    }

    public function repairReady(Repair $repair, ?int $userId): CustomerNotification
    {
        $shop = Shop::findOrFail($repair->shop_id);
        $customer = $repair->customer_id ? Customer::withoutGlobalScope(ShopScope::class)->find($repair->customer_id) : null;

        if ($customer === null) {
            throw new RuntimeException('This repair has no customer attached.');
        }

        $connection = $this->connectionFor($shop, NotificationType::RepairReady);
        $contact = CustomerMessenger::contactForCustomer($customer, $connection)
            ?? throw new RuntimeException("{$customer->name} has no valid phone number.");

        $due = $repair->amountOwed();
        $dueLine = $due > 0 ? WhatsAppLanguage::t($contact, 'repair_ready_due', ['amount' => self::money($due)]) : '';
        $text = trim(WhatsAppLanguage::t($contact, 'repair_ready', [
            'name' => $customer->name,
            'device' => $repair->categoryLabel(),
            'ref' => $repair->receiptNumber(),
            'shop' => $shop->invoiceDisplayName(),
        ])."\n".$dueLine);

        return $this->messenger->deliver(
            $connection, $contact, NotificationType::RepairReady, $repair,
            $text, [],
            'repair_ready', [$customer->name, $repair->categoryLabel(), $repair->receiptNumber(), $shop->invoiceDisplayName(), $dueLine !== '' ? $dueLine : 'Thank you!'],
            $userId,
        );
    }

    public function paymentReminderPreview(Customer $customer, ?WhatsAppContact $contact = null): array
    {
        $statement = CustomerDuesStatement::for($customer);
        $items = $statement->items();
        $udhaar = $statement->udhaarBalance();
        $lines = [];

        $askedFor = 0.0;

        foreach ($items as $item) {
            $plan = $item['installments'] ?? null;

            if ($plan === null) {
                $askedFor += (float) $item['amountDue'];
                $lines[] = WhatsAppLanguage::t($contact, 'due.'.$item['source'], [
                    'label' => $item['label'],
                    'ref' => $item['reference'],
                    'amount' => self::money((float) $item['amountDue']),
                ]);
            } elseif ($plan['dueNow'] > 0) {
                // On instalments: ask for what has fallen due, not the whole balance.
                $askedFor += $plan['dueNow'];
                $lines[] = WhatsAppLanguage::t($contact, 'due.installment_due', [
                    'label' => $item['label'],
                    'ref' => $item['reference'],
                    'amount' => self::money($plan['dueNow']),
                    'left' => self::money((float) $item['amountDue']),
                ]);
            } elseif ($plan['next'] !== null) {
                $lines[] = WhatsAppLanguage::t($contact, 'due.installment_next', [
                    'label' => $item['label'],
                    'ref' => $item['reference'],
                    'amount' => self::money($plan['next']['left']),
                    'date' => $plan['next']['due_date']->format('d M Y'),
                ]);
            }
        }

        if ($udhaar > 0) {
            $lines[] = WhatsAppLanguage::t($contact, 'due.udhaar', ['amount' => self::money($udhaar)]);
        } elseif ($udhaar < 0) {
            $lines[] = WhatsAppLanguage::t($contact, 'due.udhaar_advance', ['amount' => self::money(abs($udhaar))]);
        }

        $total = $udhaar + $askedFor;
        $link = $customer->ledgerUrl();
        $canPay = PaymentGatewayConnection::activeFor($customer->shop_id) !== null;

        return [
            'lines' => $lines,
            'total' => $total,
            'link' => $link,
            'text' => WhatsAppLanguage::t($contact, 'reminder_intro', ['name' => $customer->name, 'shop' => Shop::find($customer->shop_id)?->invoiceDisplayName()])
                ."\n\n".collect($lines)->map(fn ($l) => '• '.$l)->implode("\n")
                ."\n\n".WhatsAppLanguage::t($contact, 'reminder_total', ['total' => self::money($total)])
                ."\n\n".WhatsAppLanguage::t($contact, $canPay ? 'reminder_link_pay' : 'reminder_link_view', ['link' => $link])
                ."\n\n".WhatsAppLanguage::t($contact, 'reminder_outro'),
        ];
    }

    public function lastReminder(Customer $customer): ?CustomerNotification
    {
        return CustomerNotification::withoutGlobalScope(ShopScope::class)
            ->where('customer_id', $customer->id)
            ->where('type', NotificationType::PaymentReminder->value)
            ->where('status', NotificationStatus::Sent->value)
            ->latest('sent_at')
            ->first();
    }

    public function paymentReminder(Customer $customer, ?int $userId): CustomerNotification
    {
        $last = $this->lastReminder($customer);

        if ($last !== null && $last->sent_at->gt(now()->subHours(self::REMINDER_COOLDOWN_HOURS))) {
            throw new RuntimeException("A reminder was already sent to {$customer->name} ".$last->sent_at->diffForHumans().'.');
        }

        // Only instalments not yet due: say so before anything else, rather than "nothing outstanding".
        if ($this->paymentReminderPreview($customer)['total'] <= 0 && CustomerDuesStatement::for($customer)->items()->contains(fn ($item) => isset($item['installments']))) {
            throw new RuntimeException("Nothing is due yet from {$customer->name}: their next instalment hasn't fallen due.");
        }

        $shop = Shop::findOrFail($customer->shop_id);
        $connection = $this->connectionFor($shop, NotificationType::PaymentReminder);
        $contact = CustomerMessenger::contactForCustomer($customer, $connection)
            ?? throw new RuntimeException("{$customer->name} has no valid phone number.");

        $preview = $this->paymentReminderPreview($customer, $contact);

        if ($preview['total'] <= 0) {
            throw new RuntimeException("{$customer->name} has nothing outstanding.");
        }

        $params = [$customer->name, $shop->invoiceDisplayName(), implode('; ', $preview['lines']), self::money($preview['total'])];

        // Outside WhatsApp's 24-hour window a template is needed: the one with the link once Meta has approved it.
        $withLink = TemplateManager::approved($connection, 'payment_reminder_link', WhatsAppLanguage::for($contact)) !== null;

        return $this->messenger->deliver(
            $connection, $contact, NotificationType::PaymentReminder, $customer,
            $preview['text'], [],
            $withLink ? 'payment_reminder_link' : 'payment_reminder', $withLink ? [...$params, $preview['link']] : $params,
            $userId,
        );
    }

    public function smsRemindersAvailable(): bool
    {
        return (bool) config('reminders.sms.enabled') && app(PlivoSms::class)->configured();
    }

    public function smsSentThisMonth(int $shopId): int
    {
        return CustomerNotification::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('delivery_mode', 'sms')
            ->where('status', NotificationStatus::Sent->value)
            ->where('sent_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * The same reminder by SMS, for customers without WhatsApp. Sent from the
     * platform's Plivo account, which costs real money per message, so it's
     * off unless switched on (config reminders.sms) and capped per shop per
     * month. Kept short enough for one SMS: the total and the ledger link.
     */
    public function paymentReminderSms(Customer $customer, ?int $userId): CustomerNotification
    {
        if (! $this->smsRemindersAvailable()) {
            throw new RuntimeException('SMS reminders aren\'t switched on.');
        }

        $last = $this->lastReminder($customer);

        if ($last !== null && $last->sent_at->gt(now()->subHours(self::REMINDER_COOLDOWN_HOURS))) {
            throw new RuntimeException("A reminder was already sent to {$customer->name} ".$last->sent_at->diffForHumans().'.');
        }

        if ($this->smsSentThisMonth($customer->shop_id) >= (int) config('reminders.sms.monthly_cap_per_shop')) {
            throw new RuntimeException('This month\'s SMS reminders are used up ('.config('reminders.sms.monthly_cap_per_shop').'). Send by WhatsApp, or wait for next month.');
        }

        if (! $customer->phone || ! PhoneNumber::isPakistaniMobile($customer->phone)) {
            throw new RuntimeException("{$customer->name} has no Pakistani mobile number.");
        }

        $preview = $this->paymentReminderPreview($customer);

        if ($preview['total'] <= 0) {
            throw new RuntimeException("{$customer->name} has nothing due right now.");
        }

        $shop = Shop::findOrFail($customer->shop_id);
        $canPay = PaymentGatewayConnection::activeFor($customer->shop_id) !== null;
        $text = self::smsText(mb_strimwidth($shop->invoiceDisplayName(), 0, 25, ''), mb_strimwidth($customer->name, 0, 20, ''), self::money($preview['total']), $preview['link'], $canPay);

        $notification = new CustomerNotification([
            'shop_id' => $customer->shop_id,
            'customer_id' => $customer->id,
            'type' => NotificationType::PaymentReminder,
            'notifiable_type' => $customer->getMorphClass(),
            'notifiable_id' => $customer->id,
            'delivery_mode' => 'sms',
            'body' => $text,
            'sent_by' => $userId,
        ]);

        try {
            $notification->wamid = app(PlivoSms::class)->send($customer->phone, $text) ?: null;
            $notification->status = NotificationStatus::Sent;
            $notification->sent_at = now();
        } catch (RuntimeException $e) {
            $notification->status = NotificationStatus::Failed;
            $notification->error = mb_substr($e->getMessage(), 0, 500);
        }

        $notification->save();

        return $notification;
    }

    /** Plain characters only, so it stays one SMS. */
    public static function smsText(string $shop, string $name, string $total, string $link, bool $canPay): string
    {
        $clean = fn (string $s) => trim(preg_replace('/[^A-Za-z0-9 .,&\-]/', '', $s) ?? '');

        return $clean($shop).': '.$clean($name).', Rs '.$total.' is due. '.($canPay ? 'See and pay' : 'See your account').': '.$link;
    }

    public function restock(RestockSubscription $subscription): CustomerNotification
    {
        $shop = Shop::findOrFail($subscription->shop_id);
        $product = Product::withoutGlobalScope(ShopScope::class)->findOrFail($subscription->product_id);
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->findOrFail($subscription->whatsapp_contact_id);
        $price = self::money($product->currentSellingPrice());

        return $this->messenger->deliver(
            $this->connectionFor($shop, NotificationType::RestockAlert), $contact, NotificationType::RestockAlert, $product,
            WhatsAppLanguage::t($contact, 'restock', ['name' => $product->name, 'shop' => $shop->invoiceDisplayName(), 'price' => $price]), [],
            'restock_alert', [$product->name, $shop->invoiceDisplayName(), $price],
        );
    }

    public function reviewRequest(WhatsAppOrder $order): CustomerNotification
    {
        $shop = Shop::findOrFail($order->shop_id);
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->findOrFail($order->whatsapp_contact_id);
        $name = $contact->displayName();

        return $this->messenger->deliver(
            $this->connectionFor($shop, NotificationType::ReviewRequest), $contact, NotificationType::ReviewRequest, $order,
            WhatsAppLanguage::t($contact, 'review_request', ['name' => $name, 'order' => $order->orderNumber(), 'shop' => $shop->invoiceDisplayName()]),
            [
                'rate:5' => WhatsAppLanguage::t($contact, 'btn_review_great'),
                'rate:4' => WhatsAppLanguage::t($contact, 'btn_review_good'),
                'rate:2' => WhatsAppLanguage::t($contact, 'btn_review_bad'),
            ],
            'review_request', [$name, $order->orderNumber(), $shop->invoiceDisplayName()],
        );
    }

    protected function connectionFor(Shop $shop, NotificationType $type)
    {
        if (! $shop->{$type->feature()->column()}) {
            throw new RuntimeException($type->feature()->label().' is not enabled for this shop.');
        }

        return CustomerMessenger::whatsappConnection($shop->id)
            ?? throw new RuntimeException('WhatsApp is not connected for this shop.');
    }
}
