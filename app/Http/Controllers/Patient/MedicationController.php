<?php

namespace App\Http\Controllers\Patient;

use App\Enums\PrescriptionSource;
use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\User;
use App\Support\PrescriptionPresenter;
use App\Support\Safety\OwnMedicationHint;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicationController extends Controller
{
    /**
     * «Мои препараты»: действующие и будущие назначения врача и препараты, которые пациент добавил сам.
     * У своего препарата, который участвует в противопоказании без решения врача, — подсказка
     * рассказать врачу (OwnMedicationHint).
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

        $hinted = OwnMedicationHint::prescriptionIds($patient);

        return Inertia::render('patient/Medications', [
            'prescriptions' => PrescriptionPresenter::presentList($prescriptions, $today, forPatient: true),
            'ownMedications' => array_map(
                fn (array $medication): array => [...$medication, 'tell_doctor' => in_array($medication['id'], $hinted, true)],
                PrescriptionPresenter::presentList($own, $today, forPatient: true),
            ),
        ]);
    }
}
