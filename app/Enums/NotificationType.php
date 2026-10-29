<?php

namespace App\Enums;

enum NotificationType: string
{
    case OrderUpdate = 'order_update';
    case RepairReady = 'repair_ready';
    case PaymentReminder = 'payment_reminder';
    case RestockAlert = 'restock_alert';
    case ReviewRequest = 'review_request';

    public function pausesAtMessageLimit(): bool
    {
        return in_array($this, [self::PaymentReminder, self::RestockAlert, self::ReviewRequest], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OrderUpdate => 'Order update',
            self::RepairReady => 'Repair ready',
            self::PaymentReminder => 'Payment reminder',
            self::RestockAlert => 'Back in stock',
            self::ReviewRequest => 'Review request',
        };
    }
}
