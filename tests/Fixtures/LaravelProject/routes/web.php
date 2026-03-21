<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CleanController;

// Route with closure - technical debt anti-pattern
Route::get('/admin', function () {
    return view('admin');
});

// Route with throttle - clean
Route::get('/api', [CleanController::class, 'index'])->middleware('throttle:60,1');
