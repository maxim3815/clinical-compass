<?php

namespace App\Http\Controllers\Patient;

use App\Actions\SetIntakeTimes;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Exceptions\PrescriptionNotChangeable;
use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\Dose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Пациент выбирает время приёмов регулярного назначения врача. Со страницы «Сегодня» (`?from=today`)
 * пациент после сохранения возвращается туда же, иначе — в «Мои препараты».
 */
class IntakeTimeController extends Controller
{
    public function edit(Request $request, Prescription $prescription): Response|RedirectResponse
    {
        $patient = $this->ownerOf($request, $prescription);

        if (! $prescription->acceptsPatientTimes($patient->localToday())) {
            return $this->outdated();
        }

        $prescription->load(['drug', 'strength', 'intakes']);

        return Inertia::render('patient/IntakeTimes', [
            'prescription' => [
                'id' => $prescription->id,
                'title' => $prescription->drug->name.', '.$prescription->strength->label(),
                'intakes' => PrescriptionIntake::sortByTimeOfDay($prescription->intakes)
                    ->map(fn (PrescriptionIntake $intake): array => [
                        'time_of_day' => $intake->time_of_day->value,
                        'label' => $intake->time_of_day->label(),
                        'dose' => Dose::label($intake->tablets, $prescription->strength->mg),
                        // Пока время не выбрано, форма заполнена подсказкой по времени суток.
                        'time' => $intake->time ?? $intake->time_of_day->defaultTime(),
                    ])
                    ->all(),
                'has_times' => $prescription->intakes->every(fn (PrescriptionIntake $intake): bool => $intake->time !== null),
            ],
            'timezone' => $patient->patientProfile?->timezone->label(),
            'fromToday' => $request->query('from') === 'today',
        ]);
    }

    public function update(Request $request, Prescription $prescription, SetIntakeTimes $setIntakeTimes): RedirectResponse
    {
        $patient = $this->ownerOf($request, $prescription);

        if (! $prescription->acceptsPatientTimes($patient->localToday())) {
            return $this->outdated();
        }

        $timesOfDay = $prescription->intakes->map(fn (PrescriptionIntake $intake): string => $intake->time_of_day->value)->all();

        $validated = $request->validate([
            'times' => ['required', 'array:'.implode(',', $timesOfDay)],
            ...collect($timesOfDay)->mapWithKeys(fn (string $timeOfDay): array => [
                "times.{$timeOfDay}" => ['required', 'date_format:H:i'],
            ])->all(),
            'times.*' => ['distinct'],
            'from' => ['nullable', Rule::in(['today'])],
        ], [
            'times' => 'Время приёма указано неверно.',
            'times.*.required' => 'Укажите время приёма.',
            'times.*.date_format' => 'Укажите время в формате ЧЧ:ММ.',
            'times.*.distinct' => 'Время совпадает с другим приёмом этого препарата.',
        ]);

        try {
            $changed = $setIntakeTimes->handle($patient, $prescription, $validated['times']);
        } catch (PrescriptionNotChangeable) {
            return $this->outdated();
        }

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => 'Время приёма сохранено.']
            : ['type' => 'info', 'message' => 'Изменений нет, время осталось прежним.']);

        return to_route(($validated['from'] ?? null) === 'today' ? 'patient.home' : 'patient.medications');
    }

    /**
     * Чужое назначение, «по необходимости» и добавленное самим пациентом здесь не открываются.
     */
    private function ownerOf(Request $request, Prescription $prescription): User
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_unless(
            $prescription->patient_id === $patient->id
                && $prescription->source === PrescriptionSource::Doctor
                && $prescription->kind === PrescriptionKind::Regular,
            404,
        );

        return $patient;
    }

    /**
     * Страница могла устареть: врач изменил или отменил назначение, или оно закончилось.
     */
    private function outdated(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => 'Назначение уже изменилось или закончилось. Проверьте актуальные назначения.']);

        return to_route('patient.medications');
    }
}
