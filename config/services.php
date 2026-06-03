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

    // ─────────────────────────────────────────────
    // AI Services
    // ─────────────────────────────────────────────
    'ai' => [
        'provider' => env('AI_PROVIDER', 'groq'),
        'openai'   => [
            'api_key'  => env('OPENAI_API_KEY'),
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'model'    => 'gpt-4o-mini',
        ],
    ],

    // ─────────────────────────────────────────────
    // Groq
    // ─────────────────────────────────────────────
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model'   => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],

    // ─────────────────────────────────────────────
    // Weather
    // ─────────────────────────────────────────────
    'openweather' => [
        'api_key'  => env('OPENWEATHER_API_KEY'),
        'endpoint' => 'https://api.openweathermap.org/data/2.5',
    ],

    // ─────────────────────────────────────────────
    // Cloudinary
    // ─────────────────────────────────────────────
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'url'        => env('CLOUDINARY_URL'),
    ],

    // ─────────────────────────────────────────────
    // Telegram Bot
    // ─────────────────────────────────────────────
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id'   => env('TELEGRAM_CHAT_ID'),
        // Chỉ gửi notification ở các môi trường này
        'enabled_envs' => explode(',', env('TELEGRAM_ENABLED_ENVS', 'production,staging')),
    ],

];
