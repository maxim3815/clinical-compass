<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Пуши на этом устройстве: напоминания о приёме пациенту, сообщения обоим.
    Route::inertia('settings/notifications', 'settings/Notifications')->name('notifications.edit');
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('push-subscriptions.destroy');

    // Установка на телефон. Подсказку об установке видит только пациент.
    Route::inertia('settings/app', 'settings/App')->middleware('role:patient')->name('app.edit');
});
