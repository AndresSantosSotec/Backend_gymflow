<?php

return [
    'public_key' => env('RECURRENTE_PUBLIC_KEY'),
    'secret_key' => env('RECURRENTE_SECRET_KEY'),
    'base_url'   => env('RECURRENTE_BASE_URL', 'https://app.recurrente.com/api'),
    'sandbox'    => env('RECURRENTE_SANDBOX', false),
    'env'        => env('RECURRENTE_ENV', 'production'),
];
