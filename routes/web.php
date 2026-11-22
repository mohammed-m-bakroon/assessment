<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

// Guests land on the login page (via the auth middleware), users on the generate page.
Route::redirect('/', 'generate')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The starter kit sends users here after login; the generate page is our home.
    Route::redirect('dashboard', 'generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generate/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/status', [GenerationController::class, 'status'])->name('generations.status');

    Route::get('admin/report', ReportController::class)->middleware('can:view-admin')->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
