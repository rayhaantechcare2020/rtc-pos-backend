<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'register', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://rtcpos.netlify.app',
        'https://www.rtcpos.netlify.app',
        'http://0.0.0.0:8080/api',
        'https://localhost:8000/api/categories',
        'https://www.rtc-pos-backend-production.up.railway.app/api',
        'https://localhost:5173',  // Add your Vite dev server
        'http://localhost:3000',   // Keep existing
        'http://127.0.0.1:5173',
        'https://127.0.0.1:8000'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,  // Important for authentication

];