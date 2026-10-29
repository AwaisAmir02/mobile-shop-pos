<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

class Turnstile
{
    public static function enabled(): bool
    {
        return filled(config('billing.turnstile.site_key')) && filled(config('billing.turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        return config('billing.turnstile.site_key');
    }

    public static function verify(?string $token, ?string $ip): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('billing.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
