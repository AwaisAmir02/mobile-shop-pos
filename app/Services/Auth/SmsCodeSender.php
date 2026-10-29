<?php

namespace App\Services\Auth;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsCodeSender
{
    public function enabled(): bool
    {
        return filled(config('verification.plivo.auth_id'))
            && filled(config('verification.plivo.auth_token'))
            && filled(config('verification.plivo.sender'));
    }

    public function send(string $phone, string $code): bool
    {
        if (! $this->enabled() || ! PhoneNumber::isPakistaniMobile($phone)) {
            return false;
        }

        $authId = config('verification.plivo.auth_id');

        try {
            $response = Http::withBasicAuth($authId, config('verification.plivo.auth_token'))
                ->acceptJson()
                ->timeout(10)
                ->post("https://api.plivo.com/v1/Account/{$authId}/Message/", [
                    'src' => config('verification.plivo.sender'),
                    'dst' => '+'.$phone,
                    'text' => "{$code} is your ".config('branding.name')." verification code. It expires in ".config('verification.code_ttl_minutes')." minutes. Don't share it with anyone.",
                ]);
        } catch (\Throwable $e) {
            Log::warning('SMS verification code failed to send.', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('SMS verification code rejected.', ['status' => $response->status(), 'error' => $response->json('error')]);

            return false;
        }

        return true;
    }
}
