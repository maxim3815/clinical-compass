<?php

namespace App\Http\Controllers\Patient;

use App\Enums\PrescriptionKind;
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
     * «Мои препараты»: действующие и будущие назначения врача.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();
        $today = $patient->localToday();

        $prescriptions = $patient->prescriptions()
            ->current($today)
            ->where('source', PrescriptionSource::Doctor)
            ->with(['drug', 'strength', 'intakes', 'prescriber'])
            ->get()
            ->sortBy(fn (Prescription $prescription): array => [
                $prescription->drug->name,
                $prescription->kind === PrescriptionKind::Regular ? 0 : 1,
                $prescription->starts_on->toDateString(),
            ])
            ->map(fn (Prescription $prescription): array => PrescriptionPresenter::present($prescription, $today))
            ->values();

        return Inertia::render('patient/Medications', [
            'prescriptions' => $prescriptions,
        ]);
    }
}
