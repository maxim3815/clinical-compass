<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionSource;
use App\Http\Controllers\Controller;
use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Adherence\PatientAdherence;
use App\Support\DisplayTime;
use App\Support\PrescriptionPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public const int PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());

        // Список строим от связей, а не от patients(): так дата начала наблюдения
        // приходит типизированной моделью, а не строкой из pivot.
        $patients = CareRelationship::query()
            ->active()
            ->where('doctor_id', $request->user()?->id)
            ->join('users', 'users.id', '=', 'care_relationships.patient_id')
            ->when($search !== '', fn (Builder $query) => $query->whereLike('users.name', '%'.self::escapeLike($search).'%'))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->select('care_relationships.*')
            ->with('patient.patientProfile')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CareRelationship $care): array => [
                'id' => $care->patient->id,
                'name' => $care->patient->name,
                'age' => $care->patient->patientProfile?->age(),
                'started_at' => DisplayTime::formatDate($care->started_at),
            ]);

        return Inertia::render('doctor/patients/Index', [
            'patients' => $patients,
            'search' => $search,
        ]);
    }

    public function show(Request $request, User $patient): Response
    {
        Gate::authorize('view', $patient);

        $care = CareRelationship::query()
            ->active()
            ->where('doctor_id', $request->user()?->id)
            ->where('patient_id', $patient->id)
            ->sole();

        $profile = $patient->patientProfile;
        $today = $patient->localToday();

        [$current, $history] = $patient->prescriptions()
            ->where('source', PrescriptionSource::Doctor)
            ->with(['drug', 'strength', 'intakes', 'prescriber'])
            ->get()
            ->partition(fn (Prescription $prescription): bool => $prescription->status($today)->isCurrent());

        $own = $patient->prescriptions()
            ->current($today)
            ->where('source', PrescriptionSource::Patient)
            ->with(['drug', 'intakes'])
            ->get();

        return Inertia::render('doctor/patients/Show', [
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'login' => $patient->login,
                'birth_date' => $profile?->birth_date?->format('d.m.Y'),
                'age' => $profile?->age(),
                'started_at' => DisplayTime::formatDate($care->started_at),
                'timezone' => $profile?->timezone->label(),
            ],
            'prescriptions' => PrescriptionPresenter::presentList($current, $today),
            // Препараты, которые пациент добавил сам: врач их видит, но не меняет.
            'ownMedications' => PrescriptionPresenter::presentList($own, $today),
            // Сначала то, что закрыто или завершилось последним.
            'prescriptionHistory' => $history
                ->sortByDesc(fn (Prescription $prescription): array => [
                    ($prescription->closed_at ?? $prescription->ends_on)?->getTimestamp(),
                    $prescription->id,
                ])
                ->map(fn (Prescription $prescription): array => PrescriptionPresenter::present($prescription, $today))
                ->values(),
            'adherence' => PatientAdherence::present($patient, $today),
            'cancelReasons' => PrescriptionCancelReason::options(),
        ]);
    }

    /**
     * Символы шаблона LIKE в запросе ищем как обычные символы.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
