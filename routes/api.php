<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\MembershipPlanController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AccessLogController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\BlogPostController;
use App\Http\Controllers\Api\CashTransactionController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\SiteSettingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\CommercialLookupController;
use App\Http\Controllers\Api\ClientVentaController;
use App\Http\Controllers\Api\MarcaController;
use App\Http\Controllers\Api\PresentacionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportExportController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\PaymentInstallmentController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\FingerprintStatusController;
use App\Http\Controllers\Api\RecurrenteController;
use App\Http\Controllers\Api\MembresiaLifecycleController;
use App\Http\Controllers\Api\PagoAdelantoController;
use App\Http\Controllers\RecurrenteWebhookController;
use App\Http\Controllers\Api\ReceiptController as ApiReceiptController;
use App\Http\Controllers\Api\RegistrationProductController;
use App\Http\Controllers\Api\RecurrenteProductoController;
use App\Services\ReceiptPdfService;
use App\Models\Receipt;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

// Public routes

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/version', function () {
    return response()->json(['version' => '1.0.0']);
});

Route::get('/time', function () {
    return response()->json(['server_time' => now()]);
});

Route::get('/bdd', function () {
    try {
        DB::connection()->getPdo();
        return response()->json(['database' => 'connected']);
    } catch (\Exception $e) {
        return response()->json(['database' => 'error', 'message' => $e->getMessage()], 500);
    }
});



Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/site-settings', [SiteSettingController::class, 'index']);
Route::get('/public/plans', [MembershipPlanController::class, 'publicPlans']);
Route::get('/public/plans/{slug}', [MembershipPlanController::class, 'publicPlanBySlug']);
Route::get('/public/products', [ProductController::class, 'publicIndex']);
Route::get('/public/fingerprint-clients', [ClientController::class, 'getFingerprintClients']);
Route::post('/public/leads', [LeadController::class, 'publicStore']);

// Access verification (Public for Kiosk/Identifier)
Route::post('/access/verify-qr', [AccessLogController::class, 'verifyQR']);
Route::post('/access/verify-fingerprint', [AccessLogController::class, 'verifyFingerprint']);

// Recurrente Webhook (público, sin autenticación — Recurrente envía aquí)
Route::post('/webhooks/recurrente', [RecurrenteWebhookController::class, 'handle']);

// Checkout público (auto-registro + pago — sin autenticación)
Route::post('/public/checkout', [RecurrenteController::class, 'publicCheckout']);

// Productos de Inscripción públicos (sin autenticación)
Route::get('/public/registration-products', [RegistrationProductController::class, 'publicProducts']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Resource routes (con middleware de permisos — OR: basta tener 1 permiso del módulo)
    Route::apiResource('clients', ClientController::class)
        ->middleware('permission:CLIENTS_VIEW,CLIENTS_CREATE,CLIENTS_EDIT,CLIENTS_DELETE');
    Route::apiResource('membership-plans', MembershipPlanController::class)
        ->middleware('permission:PLANS_VIEW,PLANS_MANAGE');
    Route::apiResource('memberships', MembershipController::class)
        ->middleware('permission:MEMBERSHIPS_VIEW,MEMBERSHIPS_MANAGE');
    Route::apiResource('payments', PaymentController::class)
        ->middleware('permission:PAYMENTS_VIEW,PAYMENTS_MANAGE');
    // ROADMAP FUTURO — Control de Acceso y Huellas Digitales
    // Route::apiResource('access-logs', AccessLogController::class)
    //     ->middleware('permission:ACCESS_VIEW,ACCESS_MANAGE');
    Route::apiResource('leads', LeadController::class)
        ->middleware('permission:CLIENTS_VIEW,CLIENTS_CREATE');
    Route::apiResource('blog-posts', BlogPostController::class)
        ->middleware('permission:SETTINGS_VIEW,SETTINGS_MANAGE');
    Route::apiResource('cash-transactions', CashTransactionController::class)
        ->middleware('permission:CASH_VIEW,CASH_MANAGE');
    Route::apiResource('inventory-items', InventoryItemController::class)
        ->middleware('permission:INVENTORY_VIEW,INVENTORY_IN,INVENTORY_OUT,INVENTORY_MANAGE');
    Route::apiResource('users', UserController::class)
        ->middleware('permission:USERS_VIEW,USERS_MANAGE');
    Route::apiResource('roles', RoleController::class)
        ->middleware('permission:ROLES_VIEW,ROLES_MANAGE');

    // Membership Plans special routes
    Route::patch('/membership-plans/{id}/toggle-published', [MembershipPlanController::class, 'togglePublished'])
        ->middleware('permission:PLANS_MANAGE');
    Route::get('/membership-plans/slug/{slug}', [MembershipPlanController::class, 'getBySlug'])
        ->middleware('permission:PLANS_VIEW,PLANS_MANAGE');

    // Membership Plans ↔ Recurrente sync
    Route::post('/membership-plans/{id}/sync-recurrente', [MembershipPlanController::class, 'syncToRecurrente'])
        ->middleware('permission:PLANS_MANAGE');
    Route::post('/membership-plans/sync-all-recurrente', [MembershipPlanController::class, 'syncAllToRecurrente'])
        ->middleware('permission:PLANS_MANAGE');
    Route::get('/membership-plans/{id}/recurrente-status', [MembershipPlanController::class, 'recurrenteStatus'])
        ->middleware('permission:PLANS_VIEW,PLANS_MANAGE');

    // ── Recurrente: Checkout, Cobros, Suscripciones, Productos de pago ──────────────────
    Route::prefix('recurrente')->middleware('permission:PAYMENTS_VIEW,PAYMENTS_MANAGE')->group(function () {
        Route::post('/checkout', [RecurrenteController::class, 'createCheckout']);
        Route::post('/checkout-productos', [RecurrenteController::class, 'createCheckoutWithProductos']);
        Route::post('/charge-card', [RecurrenteController::class, 'chargeCard']);
        Route::post('/subscriptions', [RecurrenteController::class, 'createSubscription']);
        Route::delete('/subscriptions/{id}', [RecurrenteController::class, 'cancelSubscription']);
        Route::get('/payments/history/{clientId}', [RecurrenteController::class, 'paymentHistory']);
        Route::get('/payments/status/{clientId}', [RecurrenteController::class, 'clientPaymentStatus']);
        // Productos de pago único (inscripción, mensualidad, curso)
        Route::get('/productos', [RecurrenteProductoController::class, 'index']);
        Route::post('/productos', [RecurrenteProductoController::class, 'store']);
        Route::delete('/productos/{id}', [RecurrenteProductoController::class, 'destroy']);
    });

    // Access verification (CRÍTICO - Core business logic)
    // Route::post('/access/verify-qr', [AccessLogController::class, 'verifyQR']); // Moved to public
    // Route::post('/access/verify-fingerprint', [AccessLogController::class, 'verifyFingerprint']); // Moved to public
    // ROADMAP FUTURO — Accesos
    // Route::get('/access/recent', [AccessLogController::class, 'recent'])
    //     ->middleware('permission:ACCESS_VIEW,ACCESS_MANAGE');
    // Route::get('/access/by-client/{clientId}', [AccessLogController::class, 'byClient'])
    //     ->middleware('permission:ACCESS_VIEW,ACCESS_MANAGE');

    // Clients special routes
    Route::get('/clients/qr/{qrCode}', [ClientController::class, 'getByQR'])
        ->middleware('permission:CLIENTS_VIEW');
    Route::get('/clients/dni/{dni}', [ClientController::class, 'getByDni'])
        ->middleware('permission:CLIENTS_VIEW');
    Route::get('/clients/statistics/all', [ClientController::class, 'statistics'])
        ->middleware('permission:CLIENTS_VIEW');
    Route::post('/clients/{id}/upload-photo', [ClientController::class, 'uploadPhoto'])
        ->middleware('permission:CLIENTS_EDIT');
    Route::delete('/clients/{id}/photo', [ClientController::class, 'removePhoto'])
        ->middleware('permission:CLIENTS_EDIT');
    // ROADMAP FUTURO — Huellas Digitales
    // Route::post('/clients/{id}/fingerprint', [ClientController::class, 'registerFingerprint'])
    //     ->middleware('permission:CLIENTS_EDIT,ACCESS_MANAGE');
    // Route::delete('/clients/{id}/fingerprint', [ClientController::class, 'removeFingerprint'])
    //     ->middleware('permission:CLIENTS_EDIT,ACCESS_MANAGE');
    // Route::get('/clients/{id}/fingerprint', [ClientController::class, 'fingerprintStatus'])
    //     ->middleware('permission:CLIENTS_VIEW,ACCESS_VIEW');
    Route::post('/clients/{id}/regenerate-qr', [ClientController::class, 'regenerateQR'])
        ->middleware('permission:CLIENTS_EDIT');
    Route::patch('/clients/{id}/status', [ClientController::class, 'toggleStatus'])
        ->middleware('permission:CLIENTS_EDIT');

    // ROADMAP FUTURO — Fingerprint Device/Server Management
    // Route::prefix('fingerprint-status')->middleware('permission:ACCESS_VIEW,ACCESS_MANAGE')->group(function () {
    //     Route::get('/device-status', [FingerprintStatusController::class, 'deviceStatus']);
    //     Route::post('/capture', [FingerprintStatusController::class, 'capture']);
    //     Route::get('/list', [FingerprintStatusController::class, 'listFingerprints']);
    //     Route::post('/sync', [FingerprintStatusController::class, 'syncAll']);
    //     Route::get('/test-connection', [FingerprintStatusController::class, 'testConnection']);
    // });

    // Memberships special routes
    Route::post('/memberships/assign', [MembershipController::class, 'assign'])
        ->middleware('permission:MEMBERSHIPS_MANAGE');

    // Payments special routes
    Route::get('/payments/client/{clientId}', [PaymentController::class, 'byClient'])
        ->middleware('permission:PAYMENTS_VIEW');
    Route::get('/payments/revenue', [PaymentController::class, 'revenue'])
        ->middleware('permission:PAYMENTS_VIEW');
    Route::get('/payments/revenue/stats', [PaymentController::class, 'revenue'])
        ->middleware('permission:PAYMENTS_VIEW');
    Route::get('/payments/corte-caja', [PaymentController::class, 'corteCaja'])
        ->middleware('permission:PAYMENTS_VIEW');
    Route::get('/payments/corte-caja/pdf', [PaymentController::class, 'corteCajaPdf'])
        ->middleware('permission:PAYMENTS_VIEW');
    Route::patch('/payments/{id}/status', [PaymentController::class, 'updateStatus'])
        ->middleware('permission:PAYMENTS_MANAGE');

    // Payment Installments (Cuotas)
    Route::prefix('installments')->middleware('permission:PAYMENTS_VIEW,PAYMENTS_MANAGE')->group(function () {
        Route::get('/', [PaymentInstallmentController::class, 'index']);
        Route::get('/summary', [PaymentInstallmentController::class, 'summary']);
        Route::get('/membership/{membershipId}', [PaymentInstallmentController::class, 'byMembership']);
        Route::get('/{id}', [PaymentInstallmentController::class, 'show']);
        Route::post('/{id}/pay', [PaymentInstallmentController::class, 'pay']);
    });

    // Receipts (Recibos y Facturas)
    Route::prefix('receipts')->middleware('permission:PAYMENTS_VIEW,PAYMENTS_MANAGE')->group(function () {
        // Specific routes BEFORE resource (to avoid conflicts)
        Route::get('/client/{clientId}', [ReceiptController::class, 'byClient']);
        Route::get('/statistics/all', [ReceiptController::class, 'statistics']);
        Route::get('/report/pdf', [ReceiptController::class, 'report']);
        Route::post('/from-payment', [ReceiptController::class, 'createFromPayment']);
        Route::post('/bulk-export', [ReceiptController::class, 'bulkExport']);
        Route::post('/bulk-download', [ReceiptController::class, 'bulkDownloadReceipts']);

        // PDF Endpoints
        Route::get('/{id}/download/receipt', [ReceiptController::class, 'downloadReceiptPdf']);
        Route::get('/{id}/download/invoice', [ReceiptController::class, 'downloadInvoicePdf']);
        Route::get('/{id}/download/ticket', [ReceiptController::class, 'downloadTicket']);
        Route::post('/{id}/generate-invoice-pdf', [ReceiptController::class, 'generateAndDownloadInvoice']);
        Route::post('/{id}/email-pdf', [ReceiptController::class, 'emailReceiptPdf']);
        Route::post('/{id}/invoice', [ReceiptController::class, 'createInvoice']);
        Route::post('/{id}/send-email', [ReceiptController::class, 'sendEmail']);
        Route::post('/{id}/mark-paid', [ReceiptController::class, 'markAsPaid']);

        // Preview Endpoints
        Route::get('/{id}/preview/receipt', [ReceiptController::class, 'previewReceipt']);
        Route::get('/{id}/preview/invoice', [ReceiptController::class, 'previewInvoice']);
        Route::get('/{id}/preview/ticket', [ReceiptController::class, 'previewTicket']);

        // DELETE explícito para evitar 404 (apiResource a veces no registra bien destroy con prefix vacío)
        Route::delete('/{id}', [ReceiptController::class, 'destroy']);

        // Resource routes LAST (for /{id} pattern matching)
        Route::apiResource('', ReceiptController::class, ['as' => 'receipts']);
    });


    // Leads special routes (BEFORE apiResource to avoid conflicts)
    Route::get('/leads/statistics/all', [LeadController::class, 'statistics'])
        ->middleware('permission:CLIENTS_VIEW');
    Route::post('/leads/{id}/convert', [LeadController::class, 'convertToClient'])
        ->middleware('permission:CLIENTS_CREATE');

    // Site Settings
    Route::post('/site-settings', [SiteSettingController::class, 'store'])
        ->middleware('permission:SETTINGS_MANAGE');
    Route::post('/site-settings/upload-hero-images', [SiteSettingController::class, 'uploadHeroImages'])
        ->middleware('permission:SETTINGS_MANAGE');
    Route::delete('/site-settings/hero-image', [SiteSettingController::class, 'deleteHeroImage'])
        ->middleware('permission:SETTINGS_MANAGE');

    // Módulos Comerciales
    Route::post('productos/upload-image', [ProductController::class, 'uploadImage'])
        ->middleware('permission:PRODUCTS_CREATE,PRODUCTS_EDIT');
    Route::apiResource('productos', ProductController::class)
        ->middleware('permission:PRODUCTS_VIEW,PRODUCTS_CREATE,PRODUCTS_EDIT,PRODUCTS_DELETE');
    Route::apiResource('inventario', InventoryController::class)
        ->middleware('permission:INVENTORY_VIEW,INVENTORY_IN,INVENTORY_OUT,INVENTORY_MANAGE');
    Route::apiResource('ventas', SaleController::class)
        ->middleware('permission:SALES_VIEW,SALES_CREATE');
    Route::apiResource('clientes-ventas', ClientVentaController::class)
        ->middleware('permission:SALES_CLIENTS_MANAGE,SALES_VIEW');
    Route::apiResource('marcas', MarcaController::class)
        ->middleware('permission:PRODUCTS_VIEW,PRODUCTS_EDIT');
    Route::apiResource('presentaciones', PresentacionController::class)
        ->middleware('permission:PRODUCTS_VIEW,PRODUCTS_EDIT');
    Route::get('/commercial/lookups', [CommercialLookupController::class, 'index'])
        ->middleware('permission:PRODUCTS_VIEW,SALES_VIEW,INVENTORY_VIEW');

    // Reportes Contables
    Route::prefix('reports')->middleware('permission:REPORTS_VIEW')->group(function () {
        Route::get('/inventario-disponible', [ReportController::class, 'inventarioDisponible']);
        Route::get('/movimientos-inventario', [ReportController::class, 'movimientosInventario']);
        Route::get('/catalogo-productos', [ReportController::class, 'catalogoProductos']);
        Route::get('/valoracion-inventario', [ReportController::class, 'valoracionInventario']);
        Route::get('/rotacion-inventario', [ReportController::class, 'rotacionInventario']);
        Route::get('/reporte-semestral', [ReportController::class, 'reporteSemestral']);

        // Exportaciones Excel
        Route::get('/inventario-disponible/excel', [ReportExportController::class, 'inventarioDisponibleExcel']);
        Route::get('/movimientos-inventario/excel', [ReportExportController::class, 'movimientosExcel']);
        Route::get('/catalogo-productos/excel', [ReportExportController::class, 'catalogoExcel']);
        Route::get('/valoracion-inventario/excel', [ReportExportController::class, 'valoracionExcel']);
        Route::get('/rotacion-inventario/excel', [ReportExportController::class, 'rotacionExcel']);
        Route::get('/reporte-semestral/excel', [ReportExportController::class, 'semestralExcel']);

        // Exportaciones PDF
        Route::get('/inventario-disponible/pdf', [ReportExportController::class, 'inventarioDisponiblePdf']);
        Route::get('/movimientos-inventario/pdf', [ReportExportController::class, 'movimientosPdf']);
        Route::get('/catalogo-productos/pdf', [ReportExportController::class, 'catalogoPdf']);
        Route::get('/valoracion-inventario/pdf', [ReportExportController::class, 'valoracionPdf']);
        Route::get('/rotacion-inventario/pdf', [ReportExportController::class, 'rotacionPdf']);
        Route::get('/reporte-semestral/pdf', [ReportExportController::class, 'semestralPdf']);
    });

    // Monitor / Logs del sistema — solo admin (MONITOR_VIEW)
    Route::prefix('monitor')->middleware('permission:MONITOR_VIEW')->group(function () {
        Route::get('/logs', [MonitorController::class, 'logs']);
        Route::get('/stats', [MonitorController::class, 'stats']);
        Route::delete('/logs', [MonitorController::class, 'clearLogs']);
    });

    // ── Productos de Inscripción (Pagos Únicos) ──────────────────────
    Route::prefix('registration-products')->middleware('permission:PRODUCTS_VIEW,PRODUCTS_CREATE,PRODUCTS_EDIT,PRODUCTS_DELETE')->group(function () {
        // CRUD básico
        Route::get('/', [RegistrationProductController::class, 'index']);
        Route::get('/{id}', [RegistrationProductController::class, 'show']);
        Route::post('/', [RegistrationProductController::class, 'store']);
        Route::patch('/{id}', [RegistrationProductController::class, 'update']);
        Route::delete('/{id}', [RegistrationProductController::class, 'destroy']);

        // Generar link de pago para una inscripción
        Route::post('/{id}/checkout', [RegistrationProductController::class, 'createCheckout']);
    });
});
