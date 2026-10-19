<?php

use App\Http\Controllers\Doctor\DrugController;
use App\Http\Controllers\Doctor\InvitationController;
use App\Http\Controllers\Doctor\PasswordResetLinkController;
use App\Http\Controllers\Doctor\PatientController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationRegistrationController;
use App\Http\Controllers\PasswordResetByLinkController;
use App\Http\Controllers\Patient\HomeController as PatientHomeController;
use App\Models\Drug;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::inertia('consent', 'Consent')->name('consent');

// Страницы одноразовых ссылок от врача: общий лимит, чтобы токены нельзя было перебирать.
Route::middleware('throttle:access-link')->group(function () {
    Route::get('invite/{token}', [InvitationRegistrationController::class, 'show'])->name('invitation.show');
    Route::post('invite/{token}', [InvitationRegistrationController::class, 'store'])->name('invitation.store');

    Route::get('password-reset/{token}', [PasswordResetByLinkController::class, 'show'])->name('password-reset.show');
    Route::post('password-reset/{token}', [PasswordResetByLinkController::class, 'store'])->name('password-reset.store');
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', HomeController::class)->name('dashboard');

    Route::middleware('role:doctor')->prefix('doctor')->name('doctor.')->group(function () {
        Route::redirect('/', '/doctor/patients')->name('home');

        Route::get('patients', [PatientController::class, 'index'])->name('patients.index');
        Route::get('patients/{patient}', [PatientController::class, 'show'])->whereNumber('patient')->name('patients.show');
        Route::post('patients/{patient}/password-reset-link', [PasswordResetLinkController::class, 'store'])
            ->whereNumber('patient')
            ->name('patients.password-reset-link.store');

        Route::get('drugs', [DrugController::class, 'index'])->name('drugs.index');
        Route::get('drugs/{drug:code}', [DrugController::class, 'show'])
            ->where('drug', Drug::CODE_PATTERN)
            ->name('drugs.show');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])
            ->whereNumber('invitation')
            ->name('invitations.revoke');
    });

    Route::middleware('role:patient')->prefix('patient')->name('patient.')->group(function () {
        Route::get('/', PatientHomeController::class)->name('home');
    });
});

require __DIR__.'/settings.php';
