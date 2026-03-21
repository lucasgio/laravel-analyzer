<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;

// No throttle middleware - triggers OWASP A04
Route::get('/users', [ApiController::class, 'index']);
Route::post('/users', [ApiController::class, 'store']);
