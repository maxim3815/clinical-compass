<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\CancelPrescription;
use App\Actions\ChangePrescription;
use App\Actions\PrescribeDrug;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionChangeOutcome;
use App\Enums\PrescriptionSource;
use App\Enums\PrescriptionStatus;
use App\Enums\TimeOfDay;
use App\Exceptions\PrescriptionNotChangeable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\PrescriptionRequest;
use App\Models\Drug;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\Safety\SafetyAcknowledgement;
use App\Support\Safety\SafetyCheck;
use App\Support\Safety\SafetyPresenter;
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

    public function create(User $patient, SafetyCheck $safetyCheck): Response
    {
        Gate::authorize('view', $patient);

        $props = $this->formProps($patient, null, null);
        $drugs = Drug::query()->whereKey(array_column($props['drugs'], 'id'))->get();

        // Итог проверки по каждому препарату: врач видит его в списке ещё до выбора.
        $props['drugSafety'] = array_map(SafetyPresenter::summary(...), $safetyCheck->forCandidates($patient, $drugs));
        $props['drugHistory'] = $this->drugHistory($patient);

        return Inertia::render('doctor/prescriptions/Form', $props);
    }

    public function store(PrescriptionRequest $request, User $patient, PrescribeDrug $prescribeDrug): RedirectResponse
    {
        /** @var User $doctor */
        $doctor = $request->user();

        $prescribeDrug->handle($doctor, $patient, $request->toData(), $request->toAcknowledgement());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Препарат назначен.']);

        return to_route('doctor.patients.show', $patient);
    }

    public function edit(User $patient, Prescription $prescription, SafetyCheck $safetyCheck): Response|RedirectResponse
    {
        $this->authorizeChange($patient, $prescription);

        if (! $prescription->isChangeableByDoctor($patient->localToday())) {
            return $this->notChangeable($patient);
        }

        // Препарат при изменении не меняется, поэтому проверку видно сразу.
        $safety = $prescription->drug === null ? null : SafetyPresenter::report($safetyCheck->forCandidate($patient, $prescription->drug, $prescription));

        return Inertia::render('doctor/prescriptions/Form', $this->formProps($patient, $prescription, $safety));
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
            $outcome = $changePrescription->handle($doctor, $prescription, $request->toData(), $request->toAcknowledgement());
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
        $this->authorizeChange($patient, $prescription);

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
     * Чужой пациент — 404. Препарат, который пациент добавил сам, врач видит, но не меняет.
     */
    private function authorizeChange(User $patient, Prescription $prescription): void
    {
        Gate::authorize('view', $patient);

        abort_if($prescription->source === PrescriptionSource::Patient, 403, PrescriptionRequest::ADDED_BY_PATIENT);
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
     * @param  array<string, mixed>|null  $safety  блок проверки при изменении; новое назначение
     *                                             форма проверяет запросом при выборе препарата
     * @return array<string, mixed>
     */
    private function formProps(User $patient, ?Prescription $prescription, ?array $safety): array
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
            'justificationLimit' => SafetyAcknowledgement::MAX_JUSTIFICATION_LENGTH,
            'safety' => $safety,
            'drugSafety' => (object) [],
            'drugHistory' => (object) [],
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
     * Что уже было с препаратом у пациента, одной строкой для выбора препарата: идёт ли назначение,
     * принимает ли он препарат сам, чем закончилось последнее назначение врача.
     *
     * @return array<int, string> id препарата => строка
     */
    private function drugHistory(User $patient): array
    {
        $today = $patient->localToday();
        $history = [];

        $prescriptions = $patient->prescriptions()
            ->whereNotNull('drug_id')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy('drug_id');

        foreach ($prescriptions as $drugId => $versions) {
            $current = $versions->first(fn (Prescription $prescription): bool => $prescription->status($today)->isCurrent());
            $closed = $versions->first(fn (Prescription $prescription): bool => $prescription->source === PrescriptionSource::Doctor
                && in_array($prescription->status($today), [PrescriptionStatus::Cancelled, PrescriptionStatus::Completed], true));

            $history[(int) $drugId] = match (true) {
                $current?->source === PrescriptionSource::Doctor => 'Уже назначен с '.$current->starts_on->format('d.m.Y'),
                $current !== null => 'Принимает, добавил пациент',
                $closed?->closed_at !== null => 'Отменён '.DisplayTime::formatDate($closed->closed_at)
                    .($closed->cancel_reason === null ? '' : ': '.mb_strtolower($closed->cancel_reason->label())),
                $closed !== null => 'Курс завершён '.$closed->ends_on?->format('d.m.Y'),
                default => null,
            };
        }

        return array_filter($history);
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
                'code' => $drug->code,
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
