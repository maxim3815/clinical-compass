<?php

use App\Http\Controllers\Doctor\ChatController;
use App\Http\Controllers\Doctor\ClinicalProfileController;
use App\Http\Controllers\Doctor\ConversationController;
use App\Http\Controllers\Doctor\DrugController;
use App\Http\Controllers\Doctor\Icd10Controller;
use App\Http\Controllers\Doctor\InvitationController;
use App\Http\Controllers\Doctor\PasswordResetLinkController;
use App\Http\Controllers\Doctor\PatientController;
use App\Http\Controllers\Doctor\PrescriptionController;
use App\Http\Controllers\Doctor\SafetyCheckController;
use App\Http\Controllers\Doctor\SafetyFindingController;
use App\Http\Controllers\Doctor\SafetyRuleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationRegistrationController;
use App\Http\Controllers\PasswordResetByLinkController;
use App\Http\Controllers\Patient\AsNeededDoseController;
use App\Http\Controllers\Patient\ChatController as PatientChatController;
use App\Http\Controllers\Patient\DoseController;
use App\Http\Controllers\Patient\HomeController as PatientHomeController;
use App\Http\Controllers\Patient\IntakeTimeController;
use App\Http\Controllers\Patient\MedicationController;
use App\Http\Controllers\Patient\OwnMedicationController;
use App\Http\Controllers\PushDoseController;
use App\Models\Drug;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::inertia('consent', 'Consent')->name('consent');
Route::inertia('design-system', 'DesignSystemShowcase')->name('design-system');

// Страницы одноразовых ссылок от врача: общий лимит, чтобы токены нельзя было перебирать.
Route::middleware('throttle:access-link')->group(function () {
    Route::get('invite/{token}', [InvitationRegistrationController::class, 'show'])->name('invitation.show');
    Route::post('invite/{token}', [InvitationRegistrationController::class, 'store'])->name('invitation.store');

    Route::get('password-reset/{token}', [PasswordResetByLinkController::class, 'show'])->name('password-reset.show');
    Route::post('password-reset/{token}', [PasswordResetByLinkController::class, 'store'])->name('password-reset.store');
});

// Кнопки «Принял» и «Через 15 минут» в напоминании. Service worker отправляет их без сессии
// и CSRF-токена, доступ — по подписанной ссылке из самого пуша.
Route::middleware(['signed:relative', 'throttle:30,1'])->prefix('push/doses')->name('push.doses.')->group(function () {
    Route::post('take', [PushDoseController::class, 'take'])->name('take');
    Route::post('snooze', [PushDoseController::class, 'snooze'])->name('snooze');
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

        // Назначение ищется только среди назначений этого пациента.
        Route::scopeBindings()->whereNumber(['patient', 'prescription'])->group(function () {
            Route::get('patients/{patient}/prescriptions/create', [PrescriptionController::class, 'create'])
                ->name('patients.prescriptions.create');
            Route::post('patients/{patient}/prescriptions', [PrescriptionController::class, 'store'])
                ->name('patients.prescriptions.store');
            Route::get('patients/{patient}/prescriptions/{prescription}/edit', [PrescriptionController::class, 'edit'])
                ->name('patients.prescriptions.edit');
            Route::put('patients/{patient}/prescriptions/{prescription}', [PrescriptionController::class, 'update'])
                ->name('patients.prescriptions.update');
            Route::post('patients/{patient}/prescriptions/{prescription}/cancel', [PrescriptionController::class, 'cancel'])
                ->name('patients.prescriptions.cancel');
        });

        // Проверка безопасности назначения: блок в форме (JSON) и решение по находке.
        Route::get('patients/{patient}/safety-check', [SafetyCheckController::class, 'show'])
            ->whereNumber('patient')
            ->name('patients.safety-check.show');
        Route::scopeBindings()->whereNumber(['patient', 'safetyFinding'])->group(function () {
            Route::post('patients/{patient}/safety-findings/{safetyFinding}/continue', [SafetyFindingController::class, 'continue'])
                ->name('patients.safety-findings.continue');
        });

        // Клинический профиль: пол, диагнозы и факты. Диагноз ищется только среди диагнозов пациента.
        Route::scopeBindings()->whereNumber(['patient', 'diagnosis'])->group(function () {
            Route::get('patients/{patient}/clinical-profile', [ClinicalProfileController::class, 'edit'])
                ->name('patients.clinical-profile.edit');
            Route::put('patients/{patient}/clinical-profile/sex', [ClinicalProfileController::class, 'updateSex'])
                ->name('patients.clinical-profile.sex');
            Route::post('patients/{patient}/diagnoses', [ClinicalProfileController::class, 'storeDiagnosis'])
                ->name('patients.diagnoses.store');
            Route::post('patients/{patient}/diagnoses/{diagnosis}/resolve', [ClinicalProfileController::class, 'resolveDiagnosis'])
                ->name('patients.diagnoses.resolve');
            Route::post('patients/{patient}/observations', [ClinicalProfileController::class, 'storeObservation'])
                ->name('patients.observations.store');
        });
        Route::get('icd10', [Icd10Controller::class, 'index'])->name('icd10.index');

        // Чат: страница диалога, а более ранние сообщения, отправка и прочтение — JSON.
        Route::get('messages', [ConversationController::class, 'index'])->name('conversations.index');
        Route::whereNumber('patient')->group(function () {
            Route::get('patients/{patient}/chat', [ChatController::class, 'show'])->name('patients.chat.show');
            Route::get('patients/{patient}/chat/messages', [ChatController::class, 'messages'])->name('patients.chat.messages');
            Route::post('patients/{patient}/chat/messages', [ChatController::class, 'store'])
                ->middleware('throttle:chat')
                ->name('patients.chat.store');
            Route::post('patients/{patient}/chat/read', [ChatController::class, 'read'])->name('patients.chat.read');
        });

        Route::get('drugs', [DrugController::class, 'index'])->name('drugs.index');
        Route::get('drugs/{drug:code}', [DrugController::class, 'show'])
            ->where('drug', Drug::CODE_PATTERN)
            ->name('drugs.show');
        Route::get('rules', [SafetyRuleController::class, 'index'])->name('rules.index');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])
            ->whereNumber('invitation')
            ->name('invitations.revoke');
    });

    Route::middleware('role:patient')->prefix('patient')->name('patient.')->group(function () {
        Route::get('/', PatientHomeController::class)->name('home');
        Route::get('medications', MedicationController::class)->name('medications');

        // Препараты, которые пациент добавил сам.
        Route::get('medications/create', [OwnMedicationController::class, 'create'])->name('own-medications.create');
        Route::post('medications', [OwnMedicationController::class, 'store'])->name('own-medications.store');
        Route::whereNumber('prescription')->group(function () {
            Route::get('medications/{prescription}/edit', [OwnMedicationController::class, 'edit'])->name('own-medications.edit');
            Route::put('medications/{prescription}', [OwnMedicationController::class, 'update'])->name('own-medications.update');
            Route::post('medications/{prescription}/stop', [OwnMedicationController::class, 'stop'])->name('own-medications.stop');
        });

        Route::get('medications/{prescription}/times', [IntakeTimeController::class, 'edit'])
            ->whereNumber('prescription')
            ->name('intake-times.edit');
        Route::put('medications/{prescription}/times', [IntakeTimeController::class, 'update'])
            ->whereNumber('prescription')
            ->name('intake-times.update');

        Route::get('chat', [PatientChatController::class, 'show'])->name('chat.show');
        Route::get('chat/messages', [PatientChatController::class, 'messages'])->name('chat.messages');
        Route::post('chat/messages', [PatientChatController::class, 'store'])->middleware('throttle:chat')->name('chat.store');
        Route::post('chat/read', [PatientChatController::class, 'read'])->name('chat.read');

        Route::put('doses/{doseEvent}', [DoseController::class, 'update'])->whereNumber('doseEvent')->name('doses.update');
        Route::delete('doses/{doseEvent}/mark', [DoseController::class, 'cancel'])->whereNumber('doseEvent')->name('doses.cancel');
        Route::post('doses/taken', [DoseController::class, 'takeAll'])->name('doses.take-all');

        Route::post('medications/{prescription}/as-needed-doses', [AsNeededDoseController::class, 'store'])
            ->whereNumber('prescription')
            ->name('as-needed-doses.store');
        Route::delete('as-needed-doses/{asNeededDose}', [AsNeededDoseController::class, 'destroy'])
            ->whereNumber('asNeededDose')
            ->name('as-needed-doses.destroy');
    });
});

require __DIR__.'/settings.php';
