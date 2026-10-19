<?php

namespace App\Http\Controllers\Patient;

use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        $doctor = $patient->currentDoctor()->with('doctorProfile')->first();

        return Inertia::render('patient/Home', [
            'doctor' => $doctor === null ? null : [
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
            'timeSetup' => $this->prescriptionsWithoutTimes($patient),
        ]);
    }

    /**
     * Назначения, у которых есть приём без выбранного времени: по ним нет плановых приёмов.
     *
     * @return array<int, array{id: int, title: string}>
     */
    private function prescriptionsWithoutTimes(User $patient): array
    {
        return $patient->prescriptions()
            ->current($patient->localToday())
            ->where('source', PrescriptionSource::Doctor)
            ->where('kind', PrescriptionKind::Regular)
            ->whereHas('intakes', fn (Builder $query) => $query->whereNull('time'))
            ->with(['drug', 'strength'])
            ->get()
            ->sortBy(fn (Prescription $prescription): array => [$prescription->drug->name, $prescription->starts_on->toDateString()])
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'title' => $prescription->drug->name.', '.$prescription->strength->label(),
            ])
            ->values()
            ->all();
    }
}
