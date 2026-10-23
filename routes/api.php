<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Comprobante\ComprobanteController;
use App\Http\Controllers\Customer\CustomerController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\CommissionCategoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductVariantController;
use App\Http\Controllers\Inventory\SimpleProductController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Inventory\UnitTypeController;
use App\Http\Controllers\Quote\QuoteController;
use App\Http\Controllers\Report\ComprobanteReportController;
use App\Http\Controllers\Sale\SaleController;
use App\Http\Controllers\Settings\BusinessSettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    Route::apiResource('products', ProductController::class);

    Route::get('/commission-categories', [CommissionCategoryController::class, 'index']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/unit-types', [UnitTypeController::class, 'index']);

    Route::get('/product-variants/{productVariant}/stock-movements', [ProductVariantController::class, 'stockMovements']);
    Route::patch('/product-variants/{productVariant}/stock', [ProductVariantController::class, 'adjustStock']);
    Route::apiResource('product-variants', ProductVariantController::class);

    Route::get('/simple-products/{simpleProduct}/stock-movements', [SimpleProductController::class, 'stockMovements']);
    Route::patch('/simple-products/{simpleProduct}/stock', [SimpleProductController::class, 'adjustStock']);
    Route::apiResource('simple-products', SimpleProductController::class);

    Route::get('/customers/{customer}/delete-summary', [CustomerController::class, 'deleteSummary']);
    Route::apiResource('customers', CustomerController::class);

    Route::get('/quotes/{quote}/convert', [QuoteController::class, 'convert']);
    Route::get('/quotes/{quote}/to-comprobante', [QuoteController::class, 'toComprobante']);
    Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'downloadPdf']);
    Route::patch('/quotes/{quote}/cancel', [QuoteController::class, 'cancel']);
    Route::patch('/quotes/{quote}/mark-paid', [QuoteController::class, 'markPaid']);
    Route::apiResource('quotes', QuoteController::class);

    Route::get('/sales/{sale}/pdf', [SaleController::class, 'downloadPdf']);
    Route::apiResource('sales', SaleController::class)->only(['index', 'show', 'store']);

    Route::get('/comprobantes/{comprobante}/pdf', [ComprobanteController::class, 'downloadPdf']);
    Route::get('/comprobantes', [ComprobanteController::class, 'index']);
    Route::post('/comprobantes', [ComprobanteController::class, 'store']);
    Route::get('/comprobantes/{comprobante}', [ComprobanteController::class, 'show']);
    Route::patch('/comprobantes/{comprobante}', [ComprobanteController::class, 'updateDate']);

    Route::get('/reports/comprobantes', [ComprobanteReportController::class, 'comprobantes']);
    Route::get('/reports/comprobantes/csv', [ComprobanteReportController::class, 'csv']);
    Route::get('/reports/comprobantes/pdf', [ComprobanteReportController::class, 'pdf']);

    Route::get('/dashboard/top-products', [DashboardController::class, 'topProducts']);

    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::get('/categories/{category}/delete-summary', [CategoryController::class, 'deleteSummary']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::get('/suppliers/{supplier}/delete-summary', [SupplierController::class, 'deleteSummary']);
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

    Route::get('/business-settings', [BusinessSettingController::class, 'show']);
    Route::put('/business-settings', [BusinessSettingController::class, 'update']);
});
