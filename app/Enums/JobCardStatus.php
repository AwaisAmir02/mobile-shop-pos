<?php

namespace App\Enums;

/** Where a job is, from the unit coming in to it going back out. */
enum JobCardStatus: string
{
    case Received = 'received';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case AwaitingParts = 'awaiting_parts';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    /** Billed and handed back: only through the register (JobCards::delivered). */
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Diagnosing => 'Checking',
            self::AwaitingApproval => 'Waiting for customer OK',
            self::AwaitingParts => 'Waiting for parts',
            self::InProgress => 'Being worked on',
            self::Ready => 'Ready',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /** @return list<self> what staff can move an open job to by hand */
    public static function settable(): array
    {
        return [self::Received, self::Diagnosing, self::AwaitingApproval, self::AwaitingParts, self::InProgress, self::Ready];
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::settable());
    }
}
