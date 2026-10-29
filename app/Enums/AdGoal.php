<?php

namespace App\Enums;

enum AdGoal: string
{
    case WhatsAppMessages = 'whatsapp_messages';
    case PostEngagement = 'post_engagement';

    public function label(): string
    {
        return match ($this) {
            self::WhatsAppMessages => 'Get WhatsApp messages',
            self::PostEngagement => 'Boost Facebook post',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WhatsAppMessages => 'People tap the ad and land in your WhatsApp chat. Orders from these chats are tracked back to this ad.',
            self::PostEngagement => 'Shows your Facebook post to more people to get likes, comments and shares.',
        };
    }

    public function objective(): string
    {
        return match ($this) {
            self::WhatsAppMessages => 'OUTCOME_ENGAGEMENT',
            self::PostEngagement => 'OUTCOME_ENGAGEMENT',
        };
    }

    public function optimizationGoal(): string
    {
        return match ($this) {
            self::WhatsAppMessages => 'CONVERSATIONS',
            self::PostEngagement => 'POST_ENGAGEMENT',
        };
    }
}
