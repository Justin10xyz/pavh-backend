<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    Route::apiResource('products', ProductController::class);

    Route::patch('/product-variants/{productVariant}/stock', [ProductVariantController::class, 'adjustStock']);
    Route::apiResource('product-variants', ProductVariantController::class);
});
