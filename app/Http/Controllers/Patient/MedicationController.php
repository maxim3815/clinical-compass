<?php

namespace App\Http\Controllers\Patient;

use App\Enums\PrescriptionSource;
use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\User;
use App\Support\PrescriptionPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicationController extends Controller
{
    /**
     * «Мои препараты»: действующие и будущие назначения врача и препараты, которые пациент добавил сам.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();
        $today = $patient->localToday();

        [$prescriptions, $own] = $patient->prescriptions()
            ->current($today)
            ->with(['drug', 'strength', 'intakes', 'prescriber'])
            ->get()
            ->partition(fn (Prescription $prescription): bool => $prescription->source === PrescriptionSource::Doctor);

        return Inertia::render('patient/Medications', [
            'prescriptions' => PrescriptionPresenter::presentList($prescriptions, $today),
            'ownMedications' => PrescriptionPresenter::presentList($own, $today),
        ]);
    }
}
