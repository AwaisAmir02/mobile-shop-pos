<?php

namespace App\Jobs;

use App\Enums\ChannelProvider;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Sale;
use App\Models\Scopes\ShopScope;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\InvoicePdfService;
use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppLanguage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppOrderUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $orderId)
    {
    }

    public function handle(InvoicePdfService $invoices): void
    {
        $order = WhatsAppOrder::withoutGlobalScope(ShopScope::class)->find($this->orderId);

        if ($order === null || $order->customer_notified_at !== null) {
            return;
        }

        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->find($order->whatsapp_contact_id);
        $connection = ShopChannelConnection::activeFor($order->shop_id, ChannelProvider::WhatsApp);

        if ($contact === null || $connection === null) {
            $this->recordFailure($order, 'WhatsApp is not connected for this shop.');

            return;
        }

        if (! $contact->isInServiceWindow()) {
            $this->recordFailure($order, 'The customer has not messaged in the last 24 hours, so WhatsApp only allows approved templates.');

            return;
        }

        $client = WhatsAppClient::for($connection);

        try {
            if ($order->status === WhatsAppOrderStatus::Accepted) {
                $sale = Sale::withoutGlobalScopes()->findOrFail($order->sale_id);
                $mediaId = $client->uploadMedia($invoices->render($sale), $sale->invoiceNumber().'.pdf', 'application/pdf');
                $client->sendDocument(
                    $contact->wa_id,
                    $mediaId,
                    $sale->invoiceNumber().'.pdf',
                    WhatsAppLanguage::t($contact, 'order_confirmed_invoice', ['order' => $order->orderNumber()])
                );
            } elseif ($order->status === WhatsAppOrderStatus::Rejected) {
                $client->sendText(
                    $contact->wa_id,
                    WhatsAppLanguage::t($contact, 'order_rejected', ['order' => $order->orderNumber()])
                        .($order->reject_reason ? "\n".WhatsAppLanguage::t($contact, 'order_rejected_reason', ['reason' => $order->reject_reason]) : '')
                        ."\n\n".WhatsAppLanguage::t($contact, 'say_hi_again')
                );
            } else {
                return;
            }
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $this->recordFailure($order, $e->getMessage());

                return;
            }

            throw $e;
        }

        $order->forceFill(['customer_notified_at' => now(), 'notify_error' => null])->save();
    }

    protected function recordFailure(WhatsAppOrder $order, string $message): void
    {
        $order->forceFill(['notify_error' => mb_substr($message, 0, 500)])->save();
    }
}
