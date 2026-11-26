<?php

use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:120,1')
        ->name('generations.estimate');
    // Pixelforge allows 60 requests/minute for the whole account: keep one user from using it all.
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('generations.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
