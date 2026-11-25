<?php

use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\PosCatalogController;
use App\Http\Controllers\PosManagementController;
use App\Http\Controllers\PosOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Super Store POS API']));

Route::prefix('api/pos')->group(function (): void {
    Route::get('/auth/session', [PosAuthController::class, 'session']);
    Route::post('/auth/login', [PosAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth', 'throttle:120,1'])->group(function (): void {
        Route::post('/auth/logout', [PosAuthController::class, 'logout']);
        Route::get('/catalog', PosCatalogController::class)->name('pos.catalog');
        Route::get('/orders', [PosOrderController::class, 'index']);
        Route::post('/orders', [PosOrderController::class, 'store']);
        Route::get('/orders/{order}', [PosOrderController::class, 'show'])->whereNumber('order');
        Route::prefix('management')->group(function (): void {
            $resources = ['products', 'categories', 'units', 'suppliers'];
            Route::get('/', [PosManagementController::class, 'index']);
            Route::post('/{resource}', [PosManagementController::class, 'store'])->whereIn('resource', $resources);
            Route::put('/{resource}/{id}', [PosManagementController::class, 'update'])->whereIn('resource', $resources)->whereNumber('id');
            Route::delete('/{resource}/{id}', [PosManagementController::class, 'destroy'])->whereIn('resource', $resources)->whereNumber('id');
        });
    });
});
