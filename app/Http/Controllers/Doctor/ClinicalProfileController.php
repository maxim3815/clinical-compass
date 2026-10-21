<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\AddDiagnosis;
use App\Actions\RecordObservation;
use App\Actions\ResolveDiagnosis;
use App\Actions\SetPatientSex;
use App\Enums\ObservationValueType;
use App\Enums\Sex;
use App\Http\Controllers\Controller;
use App\Models\Diagnosis;
use App\Models\Icd10Code;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\User;
use App\Support\Safety\ClinicalProfilePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Клинический профиль пациента: пол, диагнозы и факты. Вносит и меняет только ведущий врач.
 */
class ClinicalProfileController extends Controller
{
    public function edit(User $patient): Response
    {
        Gate::authorize('view', $patient);

        return Inertia::render('doctor/clinical-profile/Edit', [
            'patient' => ['id' => $patient->id, 'name' => $patient->name],
            'profile' => ClinicalProfilePresenter::edit($patient),
            'sexOptions' => Sex::options(),
            'today' => $patient->localToday()->toDateString(),
            'limits' => ['comment' => Observation::MAX_COMMENT_LENGTH],
        ]);
    }

    public function updateSex(Request $request, User $patient, SetPatientSex $setPatientSex): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate(
            ['sex' => ['nullable', Rule::enum(Sex::class)]],
            ['sex' => 'Выберите пол из списка.'],
        );

        $setPatientSex->handle($this->doctor($request), $patient, Sex::tryFrom((string) ($validated['sex'] ?? '')));

        return $this->saved($patient, 'Пол сохранён.');
    }

    public function storeDiagnosis(Request $request, User $patient, AddDiagnosis $addDiagnosis): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $today = $patient->localToday()->toDateString();
        $validated = $request->validate([
            'icd10_code' => ['required', 'string', Rule::exists('icd10_codes', 'code')],
            'is_main' => ['required', 'boolean'],
            'established_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ], [
            'icd10_code.required' => 'Выберите диагноз.',
            'icd10_code' => 'Выберите диагноз из справочника МКБ-10.',
            'is_main' => 'Укажите, основной это диагноз или сопутствующий.',
            'established_on.before_or_equal' => 'Дата установления не может быть позже сегодняшнего дня.',
            'established_on' => 'Укажите дату установления.',
        ]);

        $addDiagnosis->handle(
            $this->doctor($request),
            $patient,
            Icd10Code::query()->whereKey($validated['icd10_code'])->firstOrFail(),
            $request->boolean('is_main'),
            CarbonImmutable::parse($validated['established_on']),
        );

        return $this->saved($patient, 'Диагноз добавлен.');
    }

    public function resolveDiagnosis(Request $request, User $patient, Diagnosis $diagnosis, ResolveDiagnosis $resolveDiagnosis): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate(
            ['resolved_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$patient->localToday()->toDateString()]],
            [
                'resolved_on.before_or_equal' => 'Дата снятия не может быть позже сегодняшнего дня.',
                'resolved_on' => 'Укажите дату снятия диагноза.',
            ],
        );

        $resolveDiagnosis->handle($this->doctor($request), $diagnosis, CarbonImmutable::parse($validated['resolved_on']));

        return $this->saved($patient, 'Диагноз снят.');
    }

    /**
     * Врач вносит одно или несколько значений фактов разом: каждое — отдельная запись, как и
     * при внесении по одному. Если одно значение неверно, не сохраняется ни одно.
     */
    public function storeObservations(Request $request, User $patient, RecordObservation $recordObservation): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $request->validate(
            ['observations' => ['required', 'list']],
            ['observations' => 'Выберите значение хотя бы у одного факта.'],
        );

        /** @var array<int, mixed> $items */
        $items = $request->input('observations');
        $types = ObservationType::query()
            ->active()
            ->whereKey(array_map(fn (mixed $item): int => is_array($item) ? (int) ($item['observation_type_id'] ?? 0) : 0, $items))
            ->get()
            ->keyBy('id');

        $rules = [];
        $messages = [];
        // Факт, который уже есть выше в списке: ошибка у повтора.
        $seen = [];

        foreach ($items as $index => $item) {
            $type = $types->get(is_array($item) ? (int) ($item['observation_type_id'] ?? 0) : 0);
            $field = "observations.{$index}";

            $rules += [
                "{$field}.observation_type_id" => ['required', Rule::exists('observation_types', 'id')->where('is_active', true), Rule::notIn($seen)],
                "{$field}.value" => match ($type?->value_type) {
                    ObservationValueType::Boolean => ['required', 'boolean'],
                    ObservationValueType::Number => ['required', 'numeric', 'decimal:0,2', 'min:'.($type->min ?? 0), 'max:'.($type->max ?? 999999)],
                    ObservationValueType::Choice => ['required', Rule::in(array_column($type->options ?? [], 'value'))],
                    null => ['nullable'],
                },
                "{$field}.comment" => ['nullable', 'string', 'max:'.Observation::MAX_COMMENT_LENGTH],
                "{$field}.observed_on" => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$patient->localToday()->toDateString()],
            ];
            $messages += [
                "{$field}.observation_type_id.not_in" => 'Один факт внесён дважды.',
                "{$field}.observation_type_id" => 'Выберите, что вносите.',
                "{$field}.value.min" => 'Значение не меньше '.ObservationType::number((float) $type?->min).'.',
                "{$field}.value.max" => 'Значение не больше '.ObservationType::number((float) $type?->max).'.',
                "{$field}.value" => 'Укажите значение.',
                "{$field}.comment" => 'Комментарий — не больше '.Observation::MAX_COMMENT_LENGTH.' символов.',
                "{$field}.observed_on.before_or_equal" => 'Дата не может быть позже сегодняшнего дня.',
                "{$field}.observed_on" => 'Укажите дату.',
            ];
            $seen[] = is_array($item) ? (int) ($item['observation_type_id'] ?? 0) : 0;
        }

        /** @var array{observations: list<array{observation_type_id: int|string, value: mixed, comment?: string|null, observed_on: string}>} $validated */
        $validated = $request->validate($rules, $messages);
        $doctor = $this->doctor($request);

        DB::transaction(function () use ($validated, $types, $recordObservation, $doctor, $patient): void {
            foreach ($validated['observations'] as $item) {
                /** @var ObservationType $type */
                $type = $types->get((int) $item['observation_type_id']);

                $recordObservation->handle(
                    $doctor,
                    $patient,
                    $type,
                    match ($type->value_type) {
                        ObservationValueType::Boolean => filter_var($item['value'], FILTER_VALIDATE_BOOLEAN),
                        ObservationValueType::Number => (float) $item['value'],
                        ObservationValueType::Choice => (string) $item['value'],
                    },
                    ($item['comment'] ?? null) === null ? null : trim((string) $item['comment']),
                    CarbonImmutable::parse($item['observed_on']),
                );
            }
        });

        return $this->saved($patient, count($validated['observations']) === 1 ? 'Факт сохранён.' : 'Факты сохранены.');
    }

    private function doctor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function saved(User $patient, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('doctor.patients.clinical-profile.edit', $patient);
    }
}
