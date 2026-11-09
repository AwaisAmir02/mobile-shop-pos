<?php

return [
    // Who hears about a new ticket. Empty turns the email off.
    'alert_email' => env('SUPPORT_ALERT_EMAIL', 'info@hisabokitab.com'),

    'disk' => 'local',
    'max_images' => 5,
    'image_max_kb' => 4096,
    'message_max_chars' => 5000,

    'tickets_per_hour' => 10,
    'messages_per_hour' => 60,

    // How often an open thread checks for new messages.
    'poll_seconds' => 15,
];
