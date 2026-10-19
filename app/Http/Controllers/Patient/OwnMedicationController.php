<?php

namespace App\Http\Controllers\Patient;

use App\Actions\AddOwnMedication;
use App\Actions\ChangeOwnMedication;
use App\Actions\StopOwnMedication;
use App\Enums\PrescriptionChangeOutcome;
use App\Enums\PrescriptionSource;
use App\Enums\TimeOfDay;
use App\Exceptions\PrescriptionNotChangeable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\OwnMedicationRequest;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Пациент добавляет, меняет и прекращает препараты от других врачей.
 */
class OwnMedicationController extends Controller
{
    public function create(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        return Inertia::render('patient/MedicationForm', $this->formProps($patient, null));
    }

    public function store(OwnMedicationRequest $request, AddOwnMedication $addOwnMedication): RedirectResponse
    {
        $addOwnMedication->handle($request->patient(), $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Препарат добавлен.']);

        return to_route('patient.medications');
    }

    public function edit(Request $request, Prescription $prescription): Response|RedirectResponse
    {
        $patient = $this->ownerOf($request, $prescription);

        if (! $prescription->isChangeableByPatient($patient->localToday())) {
            return $this->outdated();
        }

        return Inertia::render('patient/MedicationForm', $this->formProps($patient, $prescription));
    }

    public function update(OwnMedicationRequest $request, Prescription $prescription, ChangeOwnMedication $changeOwnMedication): RedirectResponse
    {
        try {
            $outcome = $changeOwnMedication->handle($request->patient(), $prescription, $request->toData());
        } catch (PrescriptionNotChangeable) {
            return $this->outdated();
        }

        Inertia::flash('toast', $outcome === PrescriptionChangeOutcome::Unchanged
            ? ['type' => 'info', 'message' => 'Изменений нет, препарат остался прежним.']
            : ['type' => 'success', 'message' => 'Изменения сохранены.']);

        return to_route('patient.medications');
    }

    public function stop(Request $request, Prescription $prescription, StopOwnMedication $stopOwnMedication): RedirectResponse
    {
        $patient = $this->ownerOf($request, $prescription);

        try {
            $stopOwnMedication->handle($patient, $prescription);
        } catch (PrescriptionNotChangeable) {
            return $this->outdated();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Приём препарата прекращён.']);

        return to_route('patient.medications');
    }

    /**
     * Назначение врача и чужой препарат здесь не открываются.
     */
    private function ownerOf(Request $request, Prescription $prescription): User
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_unless($prescription->patient_id === $patient->id && $prescription->source === PrescriptionSource::Patient, 404);

        return $patient;
    }

    /**
     * Страница могла устареть: препарат уже изменили или прекратили в другой вкладке, или он закончился.
     */
    private function outdated(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => 'Препарат уже изменён, прекращён или закончился. Проверьте актуальный список.']);

        return to_route('patient.medications');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(User $patient, ?Prescription $prescription): array
    {
        $today = $patient->localToday();

        return [
            'drugs' => Drug::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->orderBy('code')
                ->get()
                ->map(fn (Drug $drug): array => ['id' => $drug->id, 'name' => $drug->name])
                ->all(),
            'timesOfDay' => array_map(
                fn (TimeOfDay $timeOfDay): array => [
                    'value' => $timeOfDay->value,
                    'label' => $timeOfDay->label(),
                    'time' => $timeOfDay->defaultTime(),
                ],
                TimeOfDay::cases(),
            ),
            'limits' => [
                'intakes' => Prescription::MAX_INTAKES,
                'daily_doses' => Prescription::MAX_DAILY_DOSES,
                'drug_name' => Prescription::MAX_DRUG_NAME_LENGTH,
                'dose' => Prescription::MAX_DOSE_LENGTH,
                'prescribed_by' => Prescription::MAX_PRESCRIBED_BY_LENGTH,
            ],
            'today' => $today->toDateString(),
            'timezone' => $patient->patientProfile?->timezone->label(),
            'prescription' => $prescription === null ? null : [
                'id' => $prescription->id,
                'drug_name' => $prescription->drugName(),
                'kind' => $prescription->kind->value,
                'dose' => $prescription->dose,
                'intakes' => PrescriptionIntake::sortByTimeOfDay($prescription->intakes)
                    ->map(fn (PrescriptionIntake $intake): array => [
                        'time_of_day' => $intake->time_of_day->value,
                        'time' => $intake->time,
                    ])
                    ->all(),
                'max_daily_doses' => $prescription->max_daily_doses,
                'starts_on' => $prescription->starts_on->toDateString(),
                'starts_on_label' => $prescription->starts_on->format('d.m.Y'),
                'ends_on' => $prescription->ends_on?->toDateString(),
                'has_started' => $prescription->starts_on->lte($today),
                'prescribed_by' => $prescription->prescribed_by,
            ],
        ];
    }
}
