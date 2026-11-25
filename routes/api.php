<?php

use App\Http\Controllers\PosCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/pos/catalog', PosCatalogController::class)->name('pos.catalog');
