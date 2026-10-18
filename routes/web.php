<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', HomeController::class)->name('dashboard');

    Route::middleware('role:doctor')->prefix('doctor')->name('doctor.')->group(function () {
        Route::inertia('/', 'doctor/Home')->name('home');
    });

    Route::middleware('role:patient')->prefix('patient')->name('patient.')->group(function () {
        Route::inertia('/', 'patient/Home')->name('home');
    });
});

require __DIR__.'/settings.php';
