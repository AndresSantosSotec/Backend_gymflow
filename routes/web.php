<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RecurrenteWebhookController;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Webhook de Recurrente (EXCLUIDO de CSRF - ver bootstrap/app.php)
|--------------------------------------------------------------------------
| Recurrente envía POST a esta URL cuando ocurre un evento de pago.
| Configure en el dashboard de Recurrente:
|   https://app.recurrente.com → Configuración → Webhooks
|   URL: https://TU_DOMINIO/webhooks/recurrente
|
*/
Route::post('/webhooks/recurrente', [RecurrenteWebhookController::class, 'handle'])
    ->name('webhooks.recurrente');

/*
|--------------------------------------------------------------------------
| Páginas de retorno después del checkout hosteado
|--------------------------------------------------------------------------
*/
Route::get('/pagos/exitoso', function () {
    return response()->json([
        'status'  => 'success',
        'message' => 'Pago procesado. El sistema lo activará en breve.',
    ]);
})->name('pagos.exitoso');

Route::get('/pagos/cancelado', function () {
    return response()->json([
        'status'  => 'cancelled',
        'message' => 'El pago fue cancelado.',
    ]);
})->name('pagos.cancelado');
