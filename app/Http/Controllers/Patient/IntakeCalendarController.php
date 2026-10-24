<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Adherence\IntakeCalendar;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Календарь приёма» пациента: месяц с итогом каждого дня и составом дня. Только просмотр:
 * отметить приём можно на «Сегодня», за сегодня и вчера.
 */
class IntakeCalendarController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();
        $patient->load('patientProfile');

        return Inertia::render('patient/IntakeCalendar', [
            'calendar' => IntakeCalendar::present($patient, $request->string('month')->toString() ?: null, $patient->localToday()),
            'hasHistory' => IntakeCalendar::hasHistory($patient),
            // Предложить написать врачу можно, только если он есть.
            'hasDoctor' => $patient->currentDoctor()->exists(),
        ]);
    }
}
