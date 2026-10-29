<?php

namespace App\Services\Auth;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Mail\VerificationCodeMail;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class VerificationCodes
{
    public function __construct(
        protected WhatsAppCodeSender $whatsapp,
        protected SmsCodeSender $sms,
    ) {}

    public function phoneChannelsAvailable(): bool
    {
        return $this->whatsapp->enabled() || $this->sms->enabled();
    }

    public function smsAvailable(): bool
    {
        return $this->sms->enabled();
    }

    public function secondsUntilResend(OtpPurpose $purpose, string $destination): int
    {
        $last = VerificationCode::where('destination', $destination)->where('purpose', $purpose->value)->latest('id')->value('created_at');

        if (! $last) {
            return 0;
        }

        return max(0, config('verification.resend_cooldown_seconds') - (int) $last->diffInSeconds(now()));
    }

    public function send(OtpPurpose $purpose, OtpChannel $channel, string $destination, ?string $ip, bool $preferSms = false, bool $deliver = true): OtpChannel
    {
        if ($this->secondsUntilResend($purpose, $destination) > 0) {
            throw new RuntimeException('Please wait a moment before requesting another code.');
        }

        $destinationKey = 'otp-dest:'.$purpose->value.':'.$destination;
        $ipKey = 'otp-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($destinationKey, config('verification.max_sends_per_destination_per_hour'))
            || RateLimiter::tooManyAttempts($ipKey, config('verification.max_sends_per_ip_per_hour'))) {
            throw new RuntimeException('Too many codes requested. Please try again later.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sentOn = $deliver ? $this->deliver($purpose, $channel, $destination, $code, $preferSms) : $channel;

        RateLimiter::hit($destinationKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        VerificationCode::where('destination', $destination)->where('purpose', $purpose->value)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        VerificationCode::create([
            'purpose' => $purpose,
            'channel' => $sentOn,
            'destination' => $destination,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(config('verification.code_ttl_minutes')),
            'ip' => $ip,
        ]);

        return $sentOn;
    }

    protected function deliver(OtpPurpose $purpose, OtpChannel $channel, string $destination, string $code, bool $preferSms): OtpChannel
    {
        if ($channel === OtpChannel::Email) {
            Mail::to($destination)->send(new VerificationCodeMail($code, $purpose));

            return OtpChannel::Email;
        }

        if (! $preferSms && $this->whatsapp->send($destination, $code)) {
            return OtpChannel::WhatsApp;
        }

        if ($this->sms->send($destination, $code)) {
            return OtpChannel::Sms;
        }

        throw new RuntimeException('We couldn’t send a code to this number right now. Please try again shortly.');
    }

    public function verify(OtpPurpose $purpose, string $destination, string $code): bool
    {
        return DB::transaction(function () use ($purpose, $destination, $code) {
            $record = VerificationCode::where('destination', $destination)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record || $record->expires_at->isPast() || $record->attempts >= config('verification.max_attempts')) {
                return false;
            }

            if (! hash_equals($record->code_hash, $this->hash(preg_replace('/\D/', '', $code)))) {
                $record->increment('attempts');

                return false;
            }

            $record->update(['consumed_at' => now()]);

            return true;
        });
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
