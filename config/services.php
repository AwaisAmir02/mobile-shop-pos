<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'allow_manual_connect' => (bool) env('META_ALLOW_MANUAL_CONNECT', false),
        'ads_max_daily_budget' => (float) env('META_ADS_MAX_DAILY_BUDGET', 50000),
    ],

    // Google Tag Manager on the public marketing site only: components/site/layout.blade.php.
    // GA4 and any other tags are set up inside the GTM container, not in code.
    // Never rendered on the app or admin, nor when APP_ENV=local. A blank container ID turns it off.
    'google_tag' => [
        'container_id' => env('GOOGLE_TAG_CONTAINER_ID', 'GTM-T9NJ569Q'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', '/auth/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    // O9: our one Shopify app, installed by each shop through OAuth (App\Storefronts\Shopify).
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        // An Admin API version Shopify still supports (each is supported for about a year).
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),
    ],

];
