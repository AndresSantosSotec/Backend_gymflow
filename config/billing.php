<?php

return [
    // Proveedor de facturación electrónica
    // Opciones: 'local', 'facturama', 'sat'
    'provider' => env('BILLING_PROVIDER', 'local'),

    // URL de la API del proveedor
    'api_url' => env('BILLING_API_URL', ''),

    // Clave API
    'api_key' => env('BILLING_API_KEY', ''),

    // Secret API
    'api_secret' => env('BILLING_API_SECRET', ''),

    // Configuración de PDF
    'pdf' => [
        'paper_size' => env('RECEIPT_PAPER_SIZE', 'a4'),
        'orientation' => env('RECEIPT_ORIENTATION', 'portrait'),
        'logo_url' => env('RECEIPT_LOGO_URL', ''),
        'footer_text' => env('RECEIPT_FOOTER_TEXT', 'Thank you for your business'),
    ],

    // Configuración de empresa
    'company' => [
        'name' => env('COMPANY_NAME', 'IronGym'),
        'tax_id' => env('COMPANY_TAX_ID', ''),
        'address' => env('COMPANY_ADDRESS', ''),
        'phone' => env('COMPANY_PHONE', '5868 7153'),
        'email' => env('COMPANY_EMAIL', ''),
    ],

    // Números de series
    'series' => [
        'receipt_prefix' => 'REC',
        'invoice_prefix' => 'INV',
        'proforma_prefix' => 'PRO',
    ],

    // Almacenamiento
    'storage' => [
        'disk' => 'public',
        'receipts_path' => 'recibos',
        'invoices_path' => 'facturas',
    ],
];
