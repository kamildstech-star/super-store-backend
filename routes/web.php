<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'Super Store POS API',
    'catalog' => '/api/pos/catalog',
]));
