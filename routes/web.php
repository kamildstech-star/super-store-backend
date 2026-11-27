<?php

use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\PosCashDrawerController;
use App\Http\Controllers\PosRefundController;
use App\Http\Controllers\PosCatalogController;
use App\Http\Controllers\PosManagementController;
use App\Http\Controllers\PosInventoryController;
use App\Http\Controllers\PosOrderController;
use App\Http\Controllers\PosPurchaseReceiptController;
use App\Http\Controllers\PosSalesReportController;
use App\Http\Controllers\PosSettingsController;
use App\Http\Controllers\PosProductImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Super Store POS API']));

Route::prefix('api/pos')->group(function (): void {
    Route::get('/auth/session', [PosAuthController::class, 'session']);
    Route::post('/auth/login', [PosAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::get('/product-images/{product}/{filename}', PosProductImageController::class)
        ->whereNumber('product')->where('filename', '[A-Za-z0-9]{40}\.(?:jpe?g|png|webp)')
        ->middleware(['auth', 'throttle:300,1'])->name('pos.product-image');

    Route::middleware(['auth', 'throttle:120,1'])->group(function (): void {
        Route::post('/auth/logout', [PosAuthController::class, 'logout']);
        Route::get('/settings', [PosSettingsController::class, 'show']);
        Route::put('/settings', [PosSettingsController::class, 'update']);
        Route::get('/catalog', PosCatalogController::class)->name('pos.catalog');
        Route::get('/purchases', [PosPurchaseReceiptController::class, 'index']);
        Route::get('/purchases/products', [PosPurchaseReceiptController::class, 'products']);
        Route::get('/purchases/{receipt}', [PosPurchaseReceiptController::class, 'show'])->whereNumber('receipt');
        Route::post('/purchases', [PosPurchaseReceiptController::class, 'store']);
        Route::get('/inventory', [PosInventoryController::class, 'index']);
        Route::get('/inventory/alerts', [PosInventoryController::class, 'alerts']);
        Route::get('/reports/sales', PosSalesReportController::class);
        Route::get('/orders/{order}/return-options', [PosRefundController::class, 'options'])->whereNumber('order');
        Route::get('/orders/{order}/refunds', [PosRefundController::class, 'index'])->whereNumber('order');
        Route::post('/orders/{order}/refund-preview', [PosRefundController::class, 'preview'])->whereNumber('order');
        Route::post('/orders/{order}/refunds', [PosRefundController::class, 'store'])->whereNumber('order');
        Route::get('/refunds/{refund}', [PosRefundController::class, 'show'])->whereNumber('refund');
        Route::get('/cash-drawer', [PosCashDrawerController::class, 'status']);
        Route::get('/cash-drawer/history', [PosCashDrawerController::class, 'history']);
        Route::post('/cash-drawer/open', [PosCashDrawerController::class, 'open']);
        Route::get('/cash-drawer/{session}/movements', [PosCashDrawerController::class, 'movements'])->whereNumber('session');
        Route::post('/cash-drawer/{session}/movements', [PosCashDrawerController::class, 'move'])->whereNumber('session');
        Route::post('/cash-drawer/{session}/close', [PosCashDrawerController::class, 'close'])->whereNumber('session');
        Route::get('/inventory/{product}/movements', [PosInventoryController::class, 'movements'])->whereNumber('product');
        Route::post('/inventory/{product}/movements', [PosInventoryController::class, 'record'])->whereNumber('product');
        Route::put('/inventory/{product}/threshold', [PosInventoryController::class, 'threshold'])->whereNumber('product');
        Route::post('/checkout', [PosOrderController::class, 'checkout']);
        Route::post('/orders/{order}/checkout', [PosOrderController::class, 'checkout'])->whereNumber('order');
        Route::get('/orders', [PosOrderController::class, 'index']);
        Route::post('/orders', [PosOrderController::class, 'store']);
        Route::put('/orders/{order}', [PosOrderController::class, 'update'])->whereNumber('order');
        Route::get('/orders/{order}', [PosOrderController::class, 'show'])->whereNumber('order');
        Route::prefix('management')->group(function (): void {
            $resources = ['products', 'categories', 'units', 'suppliers', 'customers'];
            Route::get('/options', [PosManagementController::class, 'options']);
            Route::get('/{resource}', [PosManagementController::class, 'index'])->whereIn('resource', $resources);
            Route::post('/{resource}', [PosManagementController::class, 'store'])->whereIn('resource', $resources);
            Route::put('/{resource}/{id}', [PosManagementController::class, 'update'])->whereIn('resource', $resources)->whereNumber('id');
            Route::delete('/{resource}/{id}', [PosManagementController::class, 'destroy'])->whereIn('resource', $resources)->whereNumber('id');
        });
    });
});
