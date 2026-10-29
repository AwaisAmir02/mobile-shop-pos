<?php

namespace App\Services\Auth;

use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCodeSender
{
    public function enabled(): bool
    {
        return filled(config('verification.whatsapp.phone_number_id'))
            && filled(config('verification.whatsapp.access_token'))
            && filled(config('verification.whatsapp.template'));
    }

    public function send(string $phone, string $code): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $response = Http::withToken(config('verification.whatsapp.access_token'))
                ->acceptJson()
                ->timeout(10)
                ->post(WhatsAppClient::graphUrl(config('verification.whatsapp.phone_number_id').'/messages'), [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'template',
                    'template' => [
                        'name' => config('verification.whatsapp.template'),
                        'language' => ['code' => config('verification.whatsapp.language')],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp verification code failed to send.', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('WhatsApp verification code rejected.', ['status' => $response->status(), 'error' => $response->json('error.message')]);

            return false;
        }

        return true;
    }
}
