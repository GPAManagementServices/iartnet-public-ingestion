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

    'iiif' => [
        'upload_url' => env('IIIF_UPLOAD_URL', ''),
        'public_base' => env('IIIF_PUBLIC_BASE', ''),
        'port' => env('IIIF_PORT', null),
        'timeout' => (int) env('IIIF_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | LibreTranslate (translation worker)
    |--------------------------------------------------------------------------
    */
    'libre_translate' => [
        'url' => rtrim((string) env('LIBRE_TRANSLATE_URL', ''), '/'),
    ],

];
