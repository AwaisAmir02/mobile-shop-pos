<?php

namespace App\Enums;

enum SocialProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
        };
    }

    public function isConfigured(): bool
    {
        return filled(config("services.{$this->value}.client_id")) && filled(config("services.{$this->value}.client_secret"));
    }

    public function autoLinksByEmail(): bool
    {
        return $this === self::Google;
    }

    public static function configured(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->isConfigured()));
    }
}
