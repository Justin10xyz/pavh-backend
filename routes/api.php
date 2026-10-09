<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Customer\CustomerController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\CommissionCategoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductVariantController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Inventory\UnitTypeController;
use App\Http\Controllers\Quote\QuoteController;
use App\Http\Controllers\Sale\SaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    Route::apiResource('products', ProductController::class);

    Route::get('/commission-categories', [CommissionCategoryController::class, 'index']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/unit-types', [UnitTypeController::class, 'index']);

    Route::patch('/product-variants/{productVariant}/stock', [ProductVariantController::class, 'adjustStock']);
    Route::apiResource('product-variants', ProductVariantController::class);

    Route::get('/customers/{customer}/delete-summary', [CustomerController::class, 'deleteSummary']);
    Route::apiResource('customers', CustomerController::class);

    Route::get('/quotes/{quote}/convert', [QuoteController::class, 'convert']);
    Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'downloadPdf']);
    Route::apiResource('quotes', QuoteController::class);

    Route::get('/sales/{sale}/pdf', [SaleController::class, 'downloadPdf']);
    Route::apiResource('sales', SaleController::class)->only(['index', 'show', 'store']);

    Route::post('/categories', [CategoryController::class, 'store']);
});
