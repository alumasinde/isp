<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Go Network Engine
    |--------------------------------------------------------------------------
    |
    | PHP is the UI/CRM layer. All router-facing work (RouterOS API calls,
    | expiry sweeps, instant suspend/restore/disconnect) is delegated to the
    | Go engine in backend/. Keep the engine on loopback and share the token
    | through NETWORK_ENGINE_TOKEN so it can never be called from a browser.
    |
    */

    'enabled' => (bool) env('NETWORK_ENGINE_ENABLED', false),

    'url' => rtrim((string) env('NETWORK_ENGINE_URL', 'http://127.0.0.1:8080'), '/'),

    'token' => env('NETWORK_ENGINE_TOKEN'),

    'timeout' => (int) env('NETWORK_ENGINE_TIMEOUT', 10),

];
