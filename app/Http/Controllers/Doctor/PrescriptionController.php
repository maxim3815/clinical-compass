<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\CancelPrescription;
use App\Actions\ChangePrescription;
use App\Actions\PrescribeDrug;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionChangeOutcome;
use App\Enums\TimeOfDay;
use App\Exceptions\PrescriptionNotChangeable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\PrescriptionRequest;
use App\Models\Drug;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PrescriptionController extends Controller
{
    public const int MAX_CANCEL_COMMENT_LENGTH = 1000;

    public function create(User $patient): Response
    {
        Gate::authorize('view', $patient);

        return Inertia::render('doctor/prescriptions/Form', $this->formProps($patient, null));
    }

    public function store(PrescriptionRequest $request, User $patient, PrescribeDrug $prescribeDrug): RedirectResponse
    {
        /** @var User $doctor */
        $doctor = $request->user();

        $prescribeDrug->handle($doctor, $patient, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Препарат назначен.']);

        return to_route('doctor.patients.show', $patient);
    }

    public function edit(User $patient, Prescription $prescription): Response|RedirectResponse
    {
        Gate::authorize('view', $patient);

        if (! $prescription->isChangeableByDoctor($patient->localToday())) {
            return $this->notChangeable($patient);
        }

        return Inertia::render('doctor/prescriptions/Form', $this->formProps($patient, $prescription));
    }

    public function update(
        PrescriptionRequest $request,
        User $patient,
        Prescription $prescription,
        ChangePrescription $changePrescription,
    ): RedirectResponse {
        /** @var User $doctor */
        $doctor = $request->user();

        try {
            $outcome = $changePrescription->handle($doctor, $prescription, $request->toData());
        } catch (PrescriptionNotChangeable) {
            return $this->notChangeable($patient);
        }

        Inertia::flash('toast', $outcome === PrescriptionChangeOutcome::Unchanged
            ? ['type' => 'info', 'message' => 'Изменений нет, назначение осталось прежним.']
            : ['type' => 'success', 'message' => 'Назначение изменено.']);

        return to_route('doctor.patients.show', $patient);
    }

    public function cancel(
        Request $request,
        User $patient,
        Prescription $prescription,
        CancelPrescription $cancelPrescription,
    ): RedirectResponse {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'reason' => ['nullable', Rule::enum(PrescriptionCancelReason::class)],
            'comment' => ['nullable', 'string', 'max:'.self::MAX_CANCEL_COMMENT_LENGTH, 'required_if:reason,'.PrescriptionCancelReason::Other->value],
        ], [
            'reason' => 'Выберите причину из списка.',
            'comment.required_if' => 'Для причины «Другое» напишите комментарий.',
            'comment' => 'Комментарий — не больше '.self::MAX_CANCEL_COMMENT_LENGTH.' символов.',
        ]);

        /** @var User $doctor */
        $doctor = $request->user();

        try {
            $cancelPrescription->handle(
                $doctor,
                $prescription,
                PrescriptionCancelReason::tryFrom((string) ($validated['reason'] ?? '')),
                $validated['comment'] ?? null,
            );
        } catch (PrescriptionNotChangeable) {
            return $this->notChangeable($patient);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Назначение отменено.']);

        return to_route('doctor.patients.show', $patient);
    }

    /**
     * Страница могла устареть: назначение уже изменили или отменили в другой вкладке.
     */
    private function notChangeable(User $patient): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => 'Назначение уже изменено, отменено или завершено. Проверьте актуальные назначения.']);

        return to_route('doctor.patients.show', $patient);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(User $patient, ?Prescription $prescription): array
    {
        $today = $patient->localToday();

        return [
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
            ],
            'drugs' => $this->drugOptions($prescription),
            'timesOfDay' => TimeOfDay::options(),
            'limits' => [
                'intakes' => Prescription::MAX_INTAKES,
                'tablets' => Prescription::MAX_TABLETS,
                'daily_doses' => Prescription::MAX_DAILY_DOSES,
                'instruction' => PrescriptionRequest::MAX_INSTRUCTION_LENGTH,
            ],
            'today' => $today->toDateString(),
            'prescription' => $prescription === null ? null : [
                'id' => $prescription->id,
                'drug_id' => $prescription->drug_id,
                'drug_strength_id' => $prescription->drug_strength_id,
                'kind' => $prescription->kind->value,
                'intakes' => PrescriptionIntake::sortByTimeOfDay($prescription->intakes)
                    ->map(fn (PrescriptionIntake $intake): array => [
                        'time_of_day' => $intake->time_of_day->value,
                        'tablets' => (float) $intake->tablets,
                    ])
                    ->all(),
                'tablets' => $prescription->tablets === null ? null : (float) $prescription->tablets,
                'max_daily_doses' => $prescription->max_daily_doses,
                'instruction' => $prescription->instruction,
                'starts_on' => $prescription->starts_on->toDateString(),
                'starts_on_label' => $prescription->starts_on->format('d.m.Y'),
                'ends_on' => $prescription->ends_on?->toDateString(),
                'has_started' => $prescription->starts_on->lte($today),
            ],
        ];
    }

    /**
     * Активные препараты справочника с активными дозировками. При изменении назначения препарат
     * не меняется, а прежнюю дозировку можно оставить, даже если её убрали из справочника.
     *
     * @return array<int, array<string, mixed>>
     */
    private function drugOptions(?Prescription $prescription): array
    {
        return Drug::query()
            ->when(
                $prescription,
                fn (Builder $query, Prescription $prescription) => $query->whereKey($prescription->drug_id),
                fn (Builder $query) => $query->where('is_active', true),
            )
            ->with(['strengths' => fn (HasMany $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->when($prescription, fn (Builder $query, Prescription $prescription) => $query->orWhere('id', $prescription->drug_strength_id)))
                ->orderBy('mg')])
            ->orderBy('name')
            ->orderBy('code')
            ->get()
            ->filter(fn (Drug $drug): bool => $drug->strengths->isNotEmpty())
            ->map(fn (Drug $drug): array => [
                'id' => $drug->id,
                'name' => $drug->name,
                'regimen' => $drug->regimen,
                'patient_instruction' => $drug->patient_instruction,
                'strengths' => $drug->strengths->map(fn (DrugStrength $strength): array => [
                    'id' => $strength->id,
                    'label' => $strength->label(),
                    'mg' => (float) $strength->mg,
                    'divisible' => $strength->divisible,
                ])->all(),
            ])
            ->values()
            ->all();
    }
}
