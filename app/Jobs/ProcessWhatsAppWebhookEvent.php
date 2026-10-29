<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsAppWebhookEvent implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    public function __construct(public int $eventId)
    {
    }

    public function handle(): void
    {
        $claimed = WebhookEvent::whereKey($this->eventId)
            ->whereNull('processed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['locked_until' => now()->addMinutes(2)]);

        if ($claimed === 0) {
            return;
        }

        $event = WebhookEvent::findOrFail($this->eventId);
        $event->increment('attempts');

        try {
            $this->process($event->payload);
            $event->forceFill(['processed_at' => now(), 'error' => null, 'locked_until' => null])->save();
        } catch (Throwable $e) {
            Log::warning('WhatsApp webhook event failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);

            $event->forceFill([
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'locked_until' => null,
                'processed_at' => $event->attempts >= self::MAX_ATTEMPTS ? now() : null,
            ])->save();
        }
    }

    protected function process(array $payload): void
    {
        $connection = ShopChannelConnection::resolveWhatsApp((string) $payload['phone_number_id']);

        if ($connection === null || ! Shop::whereKey($connection->shop_id)->where('whatsapp_enabled', true)->exists()) {
            return;
        }

        $chatbot = new WhatsAppChatbot($connection);

        match ($payload['kind']) {
            'message' => $chatbot->handleInbound($payload['item'], $payload['profile_name'] ?? null),
            'echo' => $chatbot->handleBusinessEcho((string) ($payload['item']['to'] ?? '')),
            'status' => $connection->forceFill([
                'last_error' => mb_substr((string) ($payload['item']['errors'][0]['title'] ?? 'Message delivery failed'), 0, 500),
            ])->save(),
            default => null,
        };
    }
}
