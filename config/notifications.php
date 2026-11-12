<?php

/*
 * Email notifications, a stand-in until push notifications are added.
 * Shop emails go to the owner's login email, or the shop's own email if the
 * owner signed up by phone; a shop with neither gets none.
 */
return [
    // Who hears about a new self-serve signup. Empty turns it off.
    'signup_alert_email' => env('SIGNUP_ALERT_EMAIL', env('SUPPORT_ALERT_EMAIL', 'info@hisabokitab.com')),
];
