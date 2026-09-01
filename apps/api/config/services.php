<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'null'),
        'base_url' => env('SMS_BASE_URL'),
        'token' => env('SMS_TOKEN'),
        'timeout' => (int) env('SMS_TIMEOUT_SECONDS', 15),
    ],

    'push' => [
        'driver' => env('PUSH_DRIVER', 'null'),
        'base_url' => env('PUSH_BASE_URL'),
        'token' => env('PUSH_TOKEN'),
        'public_key' => env('PUSH_VAPID_PUBLIC_KEY'),
        'timeout' => (int) env('PUSH_TIMEOUT_SECONDS', 15),
    ],

];
