<?php

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends an SMS through the platform's Plivo account (config verification.plivo,
 * shared with SMS login codes). Throws with a reason the shop can read.
 */
class PlivoSms
{
    public function configured(): bool
    {
        return filled(config('verification.plivo.auth_id'))
            && filled(config('verification.plivo.auth_token'))
            && filled(config('verification.plivo.sender'));
    }

    /** @return string Plivo's message id */
    public function send(string $phone, string $text): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('SMS isn\'t set up on this server yet.');
        }

        $digits = PhoneNumber::normalize($phone);

        if (! PhoneNumber::isPakistaniMobile($phone)) {
            throw new RuntimeException('That isn\'t a Pakistani mobile number.');
        }

        $authId = (string) config('verification.plivo.auth_id');

        try {
            $response = Http::withBasicAuth($authId, (string) config('verification.plivo.auth_token'))
                ->acceptJson()
                ->timeout(15)
                ->post("https://api.plivo.com/v1/Account/{$authId}/Message/", [
                    'src' => config('verification.plivo.sender'),
                    'dst' => '+'.$digits,
                    'text' => $text,
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('Couldn\'t reach the SMS service. Try again in a minute.');
        }

        if ($response->failed()) {
            throw new RuntimeException('The SMS service refused the message: '.($response->json('error') ?: 'HTTP '.$response->status()));
        }

        return (string) ($response->json('message_uuid.0') ?? '');
    }
}
