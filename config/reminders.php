<?php

/*
 * Payment reminders by SMS, sent from the platform's Plivo account (the same
 * PLIVO_* settings the SMS login codes use). Every SMS costs real money:
 * Plivo charges about $0.26 to $0.47 per message to Pakistani networks
 * (plivo.com/sms/pricing/pk), so it stays off until switched on here, and
 * each shop has a monthly cap.
 */
return [
    'sms' => [
        'enabled' => (bool) env('REMINDER_SMS_ENABLED', false),
        'monthly_cap_per_shop' => (int) env('REMINDER_SMS_MONTHLY_CAP', 100),
    ],
];
