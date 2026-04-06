<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],
        // 'http://localhost:5173',  // Add your Vite dev server
        // 'http://localhost:3000',   // Keep existing
        // 'http://127.0.0.1:5173',
        // 'http://127.0.0.1:8000'
    //],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,  // Important for authentication

];