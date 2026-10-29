<?php

namespace App\Enums;

enum AdCampaignStatus: string
{
    case Draft = 'draft';
    case Creating = 'creating';
    case Ready = 'ready';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Creating => 'Setting up on Meta',
            self::Ready => 'Ready to launch',
            self::Active => 'Running',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Failed => 'Setup failed',
            self::Ended => 'Stopped',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Ready, self::Paused, self::Creating => 'warning',
            self::Failed => 'danger',
            self::Draft, self::Completed, self::Ended => 'neutral',
        };
    }

    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::Paused], true);
    }
}
