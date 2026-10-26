<?php

use App\Http\Controllers\Doctor\AdverseEventController;
use App\Http\Controllers\Doctor\AlertController;
use App\Http\Controllers\Doctor\AppointmentInvitationController;
use App\Http\Controllers\Doctor\ChatController;
use App\Http\Controllers\Doctor\ClinicalProfileController;
use App\Http\Controllers\Doctor\ConversationController;
use App\Http\Controllers\Doctor\DoctorScaleController;
use App\Http\Controllers\Doctor\DrugController;
use App\Http\Controllers\Doctor\Icd10Controller;
use App\Http\Controllers\Doctor\InvitationController;
use App\Http\Controllers\Doctor\PasswordResetLinkController;
use App\Http\Controllers\Doctor\PatientController;
use App\Http\Controllers\Doctor\PrescriptionController;
use App\Http\Controllers\Doctor\QueueAnswerController;
use App\Http\Controllers\Doctor\QueueAnswerScaleController;
use App\Http\Controllers\Doctor\ReferralController;
use App\Http\Controllers\Doctor\SafetyCheckController;
use App\Http\Controllers\Doctor\SafetyFindingController;
use App\Http\Controllers\Doctor\SafetyRuleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationRegistrationController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\PasswordResetByLinkController;
use App\Http\Controllers\Patient\AsNeededDoseController;
use App\Http\Controllers\Patient\AssessmentController;
use App\Http\Controllers\Patient\ChatController as PatientChatController;
use App\Http\Controllers\Patient\ConsentController;
use App\Http\Controllers\Patient\DoseController;
use App\Http\Controllers\Patient\HelpController;
use App\Http\Controllers\Patient\HomeController as PatientHomeController;
use App\Http\Controllers\Patient\IntakeCalendarController;
use App\Http\Controllers\Patient\IntakeTimeController;
use App\Http\Controllers\Patient\MedicationController;
use App\Http\Controllers\Patient\OwnMedicationController;
use App\Http\Controllers\Patient\RulesController;
use App\Http\Controllers\Patient\SideEffectController;
use App\Http\Controllers\PushDoseController;
use App\Http\Controllers\QueueController;
use App\Models\Drug;
use App\Models\Scale;
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

// Адрес врача для подключения на приёме. Неверные PIN считает PinAttempts, общий лимит
// не даёт перебирать коды врачей.
Route::middleware('throttle:join')->group(function () {
    Route::get('join/{joinCode}', [JoinController::class, 'show'])->where('joinCode', '[A-Za-z0-9]+')->name('join.show');
    Route::post('join/{joinCode}', [JoinController::class, 'store'])->where('joinCode', '[A-Za-z0-9]+')->name('join.store');
});

// Опросники у двери терапевта: пациент в очереди отвечает без регистрации и получает код.
Route::where(['joinCode' => '[A-Za-z0-9]+'])->group(function () {
    Route::get('q/{joinCode}', [QueueController::class, 'show'])->middleware('throttle:join')->name('queue.show');
    Route::post('q/{joinCode}', [QueueController::class, 'store'])->middleware('throttle:queue')->name('queue.store');
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

        // Список и карточка — у психиатра и у терапевта свои (кабинет по виду врача).
        Route::get('patients', [PatientController::class, 'index'])->name('patients.index');
        Route::get('patients/{patient}', [PatientController::class, 'show'])->whereNumber('patient')->name('patients.show');
        Route::post('patients/{patient}/password-reset-link', [PasswordResetLinkController::class, 'store'])
            ->whereNumber('patient')
            ->name('patients.password-reset-link.store');

        // Шкала врача на приёме: HARS у психиатра, HADS у терапевта, чужая отвечает 404. Шкала общая,
        // а не шкала пациента: без withoutScopedBindings ключ {scale:code} искался бы через пациента.
        Route::withoutScopedBindings()->whereNumber('patient')->where(['scale' => Scale::CODE_PATTERN])->group(function () {
            Route::get('patients/{patient}/scales/{scale:code}/create', [DoctorScaleController::class, 'create'])
                ->name('patients.scales.create');
            Route::post('patients/{patient}/scales/{scale:code}', [DoctorScaleController::class, 'store'])
                ->name('patients.scales.store');
        });

        // Алерт: отметка «показан» из браузера (JSON), «Принял» и закрытие решением.
        Route::scopeBindings()->whereNumber(['patient', 'alert'])->group(function () {
            Route::post('patients/{patient}/alerts/{alert}/shown', [AlertController::class, 'shown'])
                ->name('patients.alerts.shown');
            Route::post('patients/{patient}/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])
                ->name('patients.alerts.acknowledge');
            Route::post('patients/{patient}/alerts/{alert}/close', [AlertController::class, 'close'])
                ->name('patients.alerts.close');
        });

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/appointment', [AppointmentInvitationController::class, 'store'])->name('invitations.appointment.store');
        Route::get('invitations/appointment/{invitation}', [AppointmentInvitationController::class, 'show'])
            ->whereNumber('invitation')
            ->name('invitations.appointment.show');
        Route::get('invitations/card', [AppointmentInvitationController::class, 'card'])->name('invitations.card');
        Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])
            ->whereNumber('invitation')
            ->name('invitations.revoke');

        // Терапевт: решение о направлении к психиатру.
        Route::middleware('doctor.kind:therapist')->group(function () {
            Route::post('patients/{patient}/referrals', [ReferralController::class, 'store'])
                ->whereNumber('patient')
                ->name('patients.referrals.store');
            Route::post('patients/{patient}/referrals/decline', [ReferralController::class, 'decline'])
                ->whereNumber('patient')
                ->name('patients.referrals.decline');
            Route::post('referrals/{referral}/withdraw', [ReferralController::class, 'withdraw'])
                ->whereNumber('referral')
                ->name('referrals.withdraw');

            // Ответы из очереди у двери: страница кода, HADS до регистрации, «Не направлять», плакат.
            Route::get('queue/poster', [QueueAnswerController::class, 'poster'])->name('queue.poster');
            Route::whereNumber('queueAnswer')->group(function () {
                Route::get('queue/{queueAnswer}', [QueueAnswerController::class, 'show'])->name('queue-answers.show');
                Route::post('queue/{queueAnswer}/decline', [QueueAnswerController::class, 'decline'])->name('queue-answers.decline');
            });
            Route::withoutScopedBindings()->whereNumber('queueAnswer')->where(['scale' => Scale::CODE_PATTERN])->group(function () {
                Route::get('queue/{queueAnswer}/scales/{scale:code}/create', [QueueAnswerScaleController::class, 'create'])
                    ->name('queue-answers.scales.create');
                Route::post('queue/{queueAnswer}/scales/{scale:code}', [QueueAnswerScaleController::class, 'store'])
                    ->name('queue-answers.scales.store');
            });
        });

        // Психиатр: ведение лечения. Терапевту эти адреса отвечают 404.
        Route::middleware('doctor.kind:psychiatrist')->group(function () {
            // Направление от терапевта: страница до принятия и «Принять пациента».
            Route::get('referrals/{referral}', [ReferralController::class, 'show'])
                ->whereNumber('referral')
                ->name('referrals.show');
            Route::post('referrals/{referral}/accept', [ReferralController::class, 'accept'])
                ->whereNumber('referral')
                ->name('referrals.accept');

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

            // Побочные эффекты: запись со слов пациента, «Просмотрено» и комментарий.
            Route::scopeBindings()->whereNumber(['patient', 'adverseEvent'])->group(function () {
                Route::post('patients/{patient}/adverse-events', [AdverseEventController::class, 'store'])
                    ->name('patients.adverse-events.store');
                Route::post('patients/{patient}/adverse-events/{adverseEvent}/review', [AdverseEventController::class, 'review'])
                    ->name('patients.adverse-events.review');
                Route::put('patients/{patient}/adverse-events/{adverseEvent}/comment', [AdverseEventController::class, 'comment'])
                    ->name('patients.adverse-events.comment');
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
                Route::post('patients/{patient}/observations', [ClinicalProfileController::class, 'storeObservations'])
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
        });
    });

    // Новый пациент сначала видит правила «Когда приложение не поможет» (EnsurePatientReadRules).
    Route::middleware(['role:patient', 'rules.read'])->prefix('patient')->name('patient.')->group(function () {
        Route::get('/', PatientHomeController::class)->name('home');
        Route::get('medications', MedicationController::class)->name('medications');
        Route::get('calendar', IntakeCalendarController::class)->name('calendar');

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

        // Опросники: раздел «Самочувствие», заполнение и экран после отправки.
        Route::get('wellbeing', [AssessmentController::class, 'index'])->name('wellbeing');
        Route::get('questionnaires', [AssessmentController::class, 'create'])->name('questionnaires.create');
        Route::post('questionnaires', [AssessmentController::class, 'store'])->name('questionnaires.store');
        Route::get('questionnaires/{assessment}/sent', [AssessmentController::class, 'sent'])
            ->whereNumber('assessment')
            ->name('questionnaires.sent');

        // Экран помощи: открытие и звонки — тихие строки для врача, «Сообщить врачу» — алерт.
        Route::get('side-effects', [SideEffectController::class, 'index'])->name('side-effects.index');
        Route::get('side-effects/create', [SideEffectController::class, 'create'])->name('side-effects.create');
        Route::post('side-effects', [SideEffectController::class, 'store'])->middleware('throttle:30,1')->name('side-effects.store');
        Route::post('side-effects/{adverseEvent}/resolve', [SideEffectController::class, 'resolve'])
            ->whereNumber('adverseEvent')
            ->name('side-effects.resolve');
        Route::delete('side-effects/{adverseEvent}/resolve', [SideEffectController::class, 'unresolve'])
            ->whereNumber('adverseEvent')
            ->name('side-effects.unresolve');

        // Правила: сразу после регистрации с «Понятно», потом из настроек и с экрана помощи.
        // Согласие из настроек открывается в кабинете, гостевая /consent — для регистрации.
        Route::get('rules', [RulesController::class, 'show'])->name('rules.show');
        Route::post('rules', [RulesController::class, 'acknowledge'])->name('rules.acknowledge');
        Route::get('consent', ConsentController::class)->name('consent');

        Route::get('help', [HelpController::class, 'show'])->name('help.show');
        Route::post('help/report', [HelpController::class, 'report'])->middleware('throttle:30,1')->name('help.report');
        Route::post('help/calls', [HelpController::class, 'call'])->middleware('throttle:60,1')->name('help.calls');

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
