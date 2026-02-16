<?php

return [
    // Configuración del servicio de lectura de huella digital
    'fingerprint' => [
        // URL del servidor Java que lee el dispositivo biométrico
        'url' => env('FINGERPRINT_SERVER_URL', 'http://localhost:8089/api'),

        // ID del dispositivo a usar
        'device_id' => env('FINGERPRINT_DEVICE_ID', 'default'),

        // Habilitar o deshabilitar verificación con el dispositivo
        'enabled' => env('FINGERPRINT_ENABLED', true),

        // Timeout para las solicitudes al servidor
        'timeout' => env('FINGERPRINT_TIMEOUT', 30),
    ],
];
