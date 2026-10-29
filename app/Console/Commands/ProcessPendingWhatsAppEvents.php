<?php

namespace App\Console\Commands;

use App\Enums\WhatsAppOrderStatus;
use App\Jobs\ProcessWhatsAppWebhookEvent;
use App\Models\WebhookEvent;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessPendingWhatsAppEvents extends Command
{
    protected $signature = 'whatsapp:sweep {--limit=50}';

    protected $description = 'Retry unprocessed WhatsApp webhook events and expire abandoned carts';

    public const CART_TTL_DAYS = 3;

    public function handle(): int
    {
        $ids = WebhookEvent::unprocessed()
            ->where('received_at', '<', now()->subMinute())
            ->where('attempts', '<', ProcessWhatsAppWebhookEvent::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessWhatsAppWebhookEvent::dispatchSync($id);
        }

        $expired = WhatsAppOrder::withoutGlobalScopes()
            ->where('status', WhatsAppOrderStatus::Collecting->value)
            ->where('updated_at', '<', now()->subDays(self::CART_TTL_DAYS))
            ->pluck('id');

        if ($expired->isNotEmpty()) {
            DB::transaction(function () use ($expired) {
                WhatsAppOrderItem::withoutGlobalScopes()->whereIn('whatsapp_order_id', $expired)->delete();
                WhatsAppOrder::withoutGlobalScopes()->whereIn('id', $expired)->update(['status' => WhatsAppOrderStatus::Expired->value]);
            });
        }

        $this->info("Retried {$ids->count()} event(s), expired {$expired->count()} cart(s).");

        return self::SUCCESS;
    }
}
