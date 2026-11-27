<?php

namespace App\Enums;

enum NotificationType: string
{
    case OrderUpdate = 'order_update';
    case RepairReady = 'repair_ready';
    case PaymentReminder = 'payment_reminder';
    case RestockAlert = 'restock_alert';
    case ReviewRequest = 'review_request';
    /** One message to many customers (App\Actions\Broadcasts), only inside WhatsApp's 24-hour window. */
    case Broadcast = 'broadcast';
    /** Services P2, automatic: a booking coming up, a membership ending, a unit due for service. */
    case AppointmentReminder = 'appointment_reminder';
    case RenewalReminder = 'membership_renewal';
    case ServiceDue = 'service_due';

    /** The WhatsApp feature a shop needs on to send this. Order updates are part of ordering. */
    public function feature(): FeatureKey
    {
        return $this === self::OrderUpdate ? FeatureKey::WhatsAppOrdering : FeatureKey::WhatsAppMessaging;
    }

    public function pausesAtMessageLimit(): bool
    {
        return in_array($this, [self::PaymentReminder, self::RestockAlert, self::ReviewRequest, self::Broadcast, self::AppointmentReminder, self::RenewalReminder, self::ServiceDue], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OrderUpdate => 'Order update',
            self::RepairReady => 'Repair ready',
            self::PaymentReminder => 'Payment reminder',
            self::RestockAlert => 'Back in stock',
            self::ReviewRequest => 'Review request',
            self::Broadcast => 'Broadcast',
            self::AppointmentReminder => 'Appointment reminder',
            self::RenewalReminder => 'Renewal reminder',
            self::ServiceDue => 'Service due',
        };
    }
}
