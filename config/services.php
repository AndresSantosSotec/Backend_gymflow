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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'recurrente' => [
        'public_key' => env('RECURRENTE_PUBLIC_KEY'),
        'secret_key' => env('RECURRENTE_SECRET_KEY'),
        'base_url'   => env('RECURRENTE_BASE_URL', 'https://app.recurrente.com/api'),
        'env'        => env('RECURRENTE_ENV', 'production'),
    ],

    'fingerprint' => [
        'url'       => env('FINGERPRINT_SERVER_URL', 'http://localhost:8089/api'),
        'device_id' => env('FINGERPRINT_DEVICE_ID', 'default'),
        'enabled'   => env('FINGERPRINT_ENABLED', true),
        'timeout'   => env('FINGERPRINT_TIMEOUT', 15),
        'min_enrollment_quality' => env('FINGERPRINT_MIN_ENROLLMENT_QUALITY', 50),
        // Obligatorio: 6 muestras (1 principal + 5 extras) con enrollment guiado v2
        'min_enrollment_samples' => env('FINGERPRINT_MIN_ENROLLMENT_SAMPLES', 6),
    ],

];
