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

    'exchange_rate' => [
        'provider' => env('EXCHANGE_RATE_PROVIDER', 'frankfurter'),
        'url' => env('EXCHANGE_RATE_URL', 'https://api.frankfurter.app'),
        'key' => env('EXCHANGE_RATE_API_KEY'),
        'base' => env('APP_BASE_CURRENCY', 'EUR'),
    ],

    // The assistant's model. Credentials come from openai-php's own config
    // (OPENAI_API_KEY); this is only the choice of model.
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

];
