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
        $connection = $this->connectionFor($shop);

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

        $connection = $this->connectionFor($shop);
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

        foreach ($items as $item) {
            $lines[] = WhatsAppLanguage::t($contact, 'due.'.$item['source'], [
                'label' => $item['label'],
                'ref' => $item['reference'],
                'amount' => self::money((float) $item['amountDue']),
            ]);
        }

        if ($udhaar > 0) {
            $lines[] = WhatsAppLanguage::t($contact, 'due.udhaar', ['amount' => self::money($udhaar)]);
        } elseif ($udhaar < 0) {
            $lines[] = WhatsAppLanguage::t($contact, 'due.udhaar_advance', ['amount' => self::money(abs($udhaar))]);
        }

        $total = $udhaar + (float) $items->sum('amountDue');

        return [
            'lines' => $lines,
            'total' => $total,
            'text' => WhatsAppLanguage::t($contact, 'reminder_intro', ['name' => $customer->name, 'shop' => Shop::find($customer->shop_id)?->invoiceDisplayName()])
                ."\n\n".collect($lines)->map(fn ($l) => '• '.$l)->implode("\n")
                ."\n\n".WhatsAppLanguage::t($contact, 'reminder_total', ['total' => self::money($total)])
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

        $shop = Shop::findOrFail($customer->shop_id);
        $connection = $this->connectionFor($shop);
        $contact = CustomerMessenger::contactForCustomer($customer, $connection)
            ?? throw new RuntimeException("{$customer->name} has no valid phone number.");

        $preview = $this->paymentReminderPreview($customer, $contact);

        if ($preview['total'] <= 0) {
            throw new RuntimeException("{$customer->name} has nothing outstanding.");
        }

        return $this->messenger->deliver(
            $connection, $contact, NotificationType::PaymentReminder, $customer,
            $preview['text'], [],
            'payment_reminder', [$customer->name, $shop->invoiceDisplayName(), implode('; ', $preview['lines']), self::money($preview['total'])],
            $userId,
        );
    }

    public function restock(RestockSubscription $subscription): CustomerNotification
    {
        $shop = Shop::findOrFail($subscription->shop_id);
        $product = Product::withoutGlobalScope(ShopScope::class)->findOrFail($subscription->product_id);
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->findOrFail($subscription->whatsapp_contact_id);
        $price = self::money($product->currentSellingPrice());

        return $this->messenger->deliver(
            $this->connectionFor($shop), $contact, NotificationType::RestockAlert, $product,
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
            $this->connectionFor($shop), $contact, NotificationType::ReviewRequest, $order,
            WhatsAppLanguage::t($contact, 'review_request', ['name' => $name, 'order' => $order->orderNumber(), 'shop' => $shop->invoiceDisplayName()]),
            [
                'rate:5' => WhatsAppLanguage::t($contact, 'btn_review_great'),
                'rate:4' => WhatsAppLanguage::t($contact, 'btn_review_good'),
                'rate:2' => WhatsAppLanguage::t($contact, 'btn_review_bad'),
            ],
            'review_request', [$name, $order->orderNumber(), $shop->invoiceDisplayName()],
        );
    }

    protected function connectionFor(Shop $shop)
    {
        if (! $shop->whatsapp_enabled) {
            throw new RuntimeException('WhatsApp is not enabled for this shop.');
        }

        return CustomerMessenger::whatsappConnection($shop->id)
            ?? throw new RuntimeException('WhatsApp is not connected for this shop.');
    }
}
