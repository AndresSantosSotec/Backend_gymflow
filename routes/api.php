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
use App\Http\Controllers\Api\PaymentInstallmentController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\FingerprintStatusController;

// Public routes
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

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Resource routes
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('membership-plans', MembershipPlanController::class);
    Route::apiResource('memberships', MembershipController::class);
    Route::apiResource('payments', PaymentController::class);
    Route::apiResource('access-logs', AccessLogController::class);
    Route::apiResource('leads', LeadController::class);
    Route::apiResource('blog-posts', BlogPostController::class);
    Route::apiResource('cash-transactions', CashTransactionController::class);
    Route::apiResource('inventory-items', InventoryItemController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);

    // Membership Plans special routes
    Route::patch('/membership-plans/{id}/toggle-published', [MembershipPlanController::class, 'togglePublished']);
    Route::get('/membership-plans/slug/{slug}', [MembershipPlanController::class, 'getBySlug']);

    // Access verification (CRÍTICO - Core business logic)
    // Route::post('/access/verify-qr', [AccessLogController::class, 'verifyQR']); // Moved to public
    // Route::post('/access/verify-fingerprint', [AccessLogController::class, 'verifyFingerprint']); // Moved to public
    Route::get('/access/recent', [AccessLogController::class, 'recent']);
    Route::get('/access/by-client/{clientId}', [AccessLogController::class, 'byClient']);

    // Clients special routes
    Route::get('/clients/qr/{qrCode}', [ClientController::class, 'getByQR']);
    Route::get('/clients/dni/{dni}', [ClientController::class, 'getByDni']);
    Route::get('/clients/statistics/all', [ClientController::class, 'statistics']);
    Route::post('/clients/{id}/upload-photo', [ClientController::class, 'uploadPhoto']);
    Route::delete('/clients/{id}/photo', [ClientController::class, 'removePhoto']);
    Route::post('/clients/{id}/fingerprint', [ClientController::class, 'registerFingerprint']);
    Route::delete('/clients/{id}/fingerprint', [ClientController::class, 'removeFingerprint']);
    Route::get('/clients/{id}/fingerprint', [ClientController::class, 'fingerprintStatus']);
    Route::post('/clients/{id}/regenerate-qr', [ClientController::class, 'regenerateQR']);
    Route::patch('/clients/{id}/status', [ClientController::class, 'toggleStatus']);

    // Fingerprint Device/Server Management
    Route::prefix('fingerprint-status')->group(function () {
        Route::get('/device-status', [FingerprintStatusController::class, 'deviceStatus']);
        Route::post('/capture', [FingerprintStatusController::class, 'capture']);
        Route::get('/list', [FingerprintStatusController::class, 'listFingerprints']);
        Route::post('/sync', [FingerprintStatusController::class, 'syncAll']);
        Route::get('/test-connection', [FingerprintStatusController::class, 'testConnection']);
    });

    // Memberships special routes
    Route::post('/memberships/assign', [MembershipController::class, 'assign']);

    // Payments special routes
    Route::get('/payments/client/{clientId}', [PaymentController::class, 'byClient']);
    Route::get('/payments/revenue/stats', [PaymentController::class, 'revenue']);
    Route::patch('/payments/{id}/status', [PaymentController::class, 'updateStatus']);

    // Payment Installments (Cuotas)
    Route::prefix('installments')->group(function () {
        Route::get('/', [PaymentInstallmentController::class, 'index']);
        Route::get('/summary', [PaymentInstallmentController::class, 'summary']);
        Route::get('/membership/{membershipId}', [PaymentInstallmentController::class, 'byMembership']);
        Route::get('/{id}', [PaymentInstallmentController::class, 'show']);
        Route::post('/{id}/pay', [PaymentInstallmentController::class, 'pay']);
    });

    // Receipts (Recibos y Facturas)
    Route::prefix('receipts')->group(function () {
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

        // Resource routes LAST (for /{id} pattern matching)
        Route::apiResource('', ReceiptController::class, ['as' => 'receipts']);
    });


    // Leads special routes (BEFORE apiResource to avoid conflicts)
    Route::get('/leads/statistics/all', [LeadController::class, 'statistics']);
    Route::post('/leads/{id}/convert', [LeadController::class, 'convertToClient']);

    // Site Settings
    Route::post('/site-settings', [SiteSettingController::class, 'store']);
    Route::post('/site-settings/upload-hero-images', [SiteSettingController::class, 'uploadHeroImages']);
    Route::delete('/site-settings/hero-image', [SiteSettingController::class, 'deleteHeroImage']);

    // Módulos Comerciales
    Route::post('productos/upload-image', [ProductController::class, 'uploadImage']);
    Route::apiResource('productos', ProductController::class);
    Route::apiResource('inventario', InventoryController::class);
    Route::apiResource('ventas', SaleController::class);
    Route::apiResource('clientes-ventas', ClientVentaController::class);
    Route::apiResource('marcas', MarcaController::class);
    Route::apiResource('presentaciones', PresentacionController::class);
    Route::get('/commercial/lookups', [CommercialLookupController::class, 'index']);

    // Reportes Contables
    Route::prefix('reports')->group(function () {
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
});
