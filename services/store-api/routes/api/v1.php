<?php

use App\Http\Controllers\Api\V1\Admin\DeliveryProgressController;
use App\Http\Controllers\Api\V1\Admin\FinancePeriodController;
use App\Http\Controllers\Api\V1\Admin\OrderAsOfController;
use App\Http\Controllers\Api\V1\Admin\ReconcileController;
use App\Http\Controllers\Api\V1\Admin\RecoveryController;
use App\Http\Controllers\Api\V1\Catalog\CatalogController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Middleware\AdminTokenMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/catalog/stock', [CatalogController::class, 'stock']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::post('/webhook/payment', PaymentWebhookController::class);

Route::middleware(AdminTokenMiddleware::class)->prefix('admin')->group(function () {
    Route::get('/reconcile', ReconcileController::class);
    Route::post('/recover', RecoveryController::class);
    Route::get('/delivery-progress', DeliveryProgressController::class);
    Route::get('/orders/{id}/as-of', OrderAsOfController::class);
    Route::get('/finance/period', FinancePeriodController::class);
});
