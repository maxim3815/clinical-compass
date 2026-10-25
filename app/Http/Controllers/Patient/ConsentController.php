<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Согласие, которое пациент принял при регистрации: перечитать из настроек, не выходя из кабинета.
 * Дату видит только сам пациент, врачу она не показывается.
 */
class ConsentController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();
        $consentedAt = $patient->patientProfile?->consented_at;

        return Inertia::render('patient/Consent', [
            // «25 октября 2026»: когда пациент дал согласие, по его часовому поясу.
            'consentedOn' => $consentedAt?->setTimezone($patient->localTimezone())->isoFormat('D MMMM YYYY'),
        ]);
    }
}
