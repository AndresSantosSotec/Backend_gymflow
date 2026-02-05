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

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

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
});
