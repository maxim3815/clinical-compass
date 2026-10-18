<?php

use App\Http\Controllers\Doctor\InvitationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationRegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::inertia('consent', 'Consent')->name('consent');

// Страницы одноразовых ссылок от врача: общий лимит, чтобы токены нельзя было перебирать.
Route::middleware('throttle:access-link')->group(function () {
    Route::get('invite/{token}', [InvitationRegistrationController::class, 'show'])->name('invitation.show');
    Route::post('invite/{token}', [InvitationRegistrationController::class, 'store'])->name('invitation.store');
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', HomeController::class)->name('dashboard');

    Route::middleware('role:doctor')->prefix('doctor')->name('doctor.')->group(function () {
        Route::inertia('/', 'doctor/Home')->name('home');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])
            ->whereNumber('invitation')
            ->name('invitations.revoke');
    });

    Route::middleware('role:patient')->prefix('patient')->name('patient.')->group(function () {
        Route::inertia('/', 'patient/Home')->name('home');
    });
});

require __DIR__.'/settings.php';
