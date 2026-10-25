<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PatientProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Правила «Как работает помощь в приложении»: новый пациент видит их сразу после регистрации
 * и нажимает «Понятно», потом перечитывает из настроек и с экрана помощи.
 */
class RulesController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        return Inertia::render('patient/Rules', [
            'acknowledged' => ! $patient->mustReadRules(),
            // Открыты с экрана помощи: ссылка назад ведёт туда, а не в настройки.
            'fromHelp' => $request->query('from') === 'help',
        ]);
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        // Повторное нажатие (вторая вкладка, двойное касание) дату не переписывает.
        $acknowledged = PatientProfile::query()
            ->whereKey($patient->id)
            ->whereNull('rules_acknowledged_at')
            ->update(['rules_acknowledged_at' => CarbonImmutable::now()]);

        if ($acknowledged === 1) {
            AuditLog::record($patient, 'patient.rules_acknowledged', $patient, $patient, null, null);
        }

        // Профиль мог быть уже прочитан в этом процессе (тестовый сервер держит пользователя между
        // запросами): без перечитывания EnsurePatientReadRules снова вернул бы на правила.
        $patient->unsetRelation('patientProfile');

        return redirect()->intended(route('patient.home'));
    }
}
