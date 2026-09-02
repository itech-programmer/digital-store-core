<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'digital-store-core',
    'api' => '/api/v1/health',
]));
