<?php

// return [

//     /*
//     |--------------------------------------------------------------------------
//     | Cross-Origin Resource Sharing (CORS) Configuration
//     |--------------------------------------------------------------------------
//     |
//     | Here you may configure your settings for cross-origin resource sharing
//     | or "CORS". This determines what cross-origin operations may execute
//     | in web browsers. You are free to adjust these settings as needed.
//     |
//     | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
//     |
//     */

//     'paths' => ['api/*', 'sanctum/csrf-cookie'],

//     'allowed_methods' => ['*'],

//     /*
//     |--------------------------------------------------------------------------
//     | Allowed Origins
//     |--------------------------------------------------------------------------
//     |
//     | In production, only the Vercel frontend domain is allowed.
//     | In local development, localhost:3000 is also permitted.
//     |
//     | Set FRONTEND_URL in .env to your Vercel deployment URL, e.g.:
//     |   FRONTEND_URL=https://tripai.vercel.app
//     |
//     */
//     'allowed_origins' => array_filter([
//         env('FRONTEND_URL', 'http://localhost:3000'),
//         // Allow localhost variants for local development
//         env('APP_ENV') !== 'production' ? 'http://localhost:3000' : null,
//         env('APP_ENV') !== 'production' ? 'http://127.0.0.1:3000' : null,
//     ]),

//     'allowed_origins_patterns' => [
//         // Allow all Vercel preview deployments for the project
//         '#^https://tripai.*\.vercel\.app$#',
//     ],

//     'allowed_headers' => ['*'],

//     'exposed_headers' => ['Authorization', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],

//     'max_age' => 86400,

//     'supports_credentials' => true,

// ];

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],   // 🔥 mở full

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false, // 🔥 QUAN TRỌNG: phải false khi dùng '*'
];
