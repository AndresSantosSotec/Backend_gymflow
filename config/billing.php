<?php

return [
    // Proveedor de facturación electrónica
    // Opciones: 'local', 'facturama', 'sat', 'corpo_fel'
    'provider' => env('BILLING_PROVIDER', 'local'),

    // URL de la API del proveedor (legacy)
    'api_url' => env('BILLING_API_URL', ''),

    // Clave API
    'api_key' => env('BILLING_API_KEY', ''),

    // Secret API
    'api_secret' => env('BILLING_API_SECRET', ''),

    // Corpo Sistemas FEL (Guatemala)
    'corpo_fel' => [
        'enabled' => env('CORPO_FEL_ENABLED', false),
        'use_test' => env('CORPO_FEL_USE_TEST', true),
        'base_url' => env('CORPO_FEL_BASE_URL', 'https://app.corposistemasgt.com/webservicefront/factwsfront.asmx'),
        'base_url_test' => env('CORPO_FEL_BASE_URL_TEST', 'https://app.corposistemasgt.com/webservicefronttest/factwsfront.asmx'),
        'nit_url' => env('CORPO_FEL_NIT_URL', 'https://app.corposistemasgt.com/webapi/GetNIT'),
        'requestor' => env('CORPO_FEL_REQUESTOR', ''),
        'entity_nit' => env('CORPO_FEL_ENTITY_NIT', ''),
        'country' => env('CORPO_FEL_COUNTRY', 'GT'),
        // Datos del emisor (licencia del gimnasio — configurar con datos reales en producción)
        'emisor_nombre' => env('CORPO_FEL_EMISOR_NOMBRE', ''),
        'emisor_nombre_comercial' => env('CORPO_FEL_EMISOR_NOMBRE_COMERCIAL', ''),
        'emisor_direccion' => env('CORPO_FEL_EMISOR_DIRECCION', ''),
        'emisor_codigo_postal' => env('CORPO_FEL_EMISOR_CP', '01001'),
        'emisor_municipio' => env('CORPO_FEL_EMISOR_MUNICIPIO', 'GUATEMALA'),
        'emisor_departamento' => env('CORPO_FEL_EMISOR_DEPARTAMENTO', 'GUATEMALA'),
        'codigo_establecimiento' => env('CORPO_FEL_CODIGO_ESTABLECIMIENTO', '1'),
        'afiliacion_iva' => env('CORPO_FEL_AFILIACION_IVA', 'GEN'),
        'frase_tipo' => env('CORPO_FEL_FRASE_TIPO', '1'),
        'frase_escenario' => env('CORPO_FEL_FRASE_ESCENARIO', '2'),
        // Efectivo: no certificar por defecto. Tarjeta/transferencia: sí.
        'auto_certify_cash' => env('CORPO_FEL_AUTO_CERTIFY_CASH', false),
        'auto_certify_non_cash' => env('CORPO_FEL_AUTO_CERTIFY_NON_CASH', true),
    ],

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
