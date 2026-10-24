<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\RateScale;
use App\Enums\ScaleRater;
use App\Http\Controllers\Controller;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Шкала врача (HARS) на приёме: форма и сохранение. Терапевт вместе с ней отмечает признаки,
 * которых нет ни в одной шкале: по ним подсказка направления ставит «обязательно».
 */
class DoctorScaleController extends Controller
{
    /**
     * Признаки, которые терапевт отмечает вместе со шкалой (виды фактов клинического профиля).
     */
    public const array THERAPIST_FACTS = ['mania_signs', 'psychotic_symptoms'];

    public function create(Request $request, User $patient, Scale $scale): Response
    {
        Gate::authorize('view', $patient);
        abort_unless($this->isDoctorScale($scale), 404);

        /** @var User $doctor */
        $doctor = $request->user();

        return Inertia::render('doctor/ScaleForm', [
            'patient' => ['id' => $patient->id, 'name' => $patient->name],
            'scale' => [
                'code' => $scale->code,
                'name' => $scale->name,
                'title' => $scale->title,
                'instruction' => $scale->instruction,
                'options' => $scale->options,
                'items' => array_map(fn (array $item): array => ['text' => $item['text'], 'hint' => $item['hint'] ?? null], $scale->items),
                'max' => $scale->maxTotal(),
                'bands' => $scale->bands,
                'source' => [
                    'validation' => $scale->source_validation,
                    'url' => $scale->source_url,
                    'note' => $scale->source_note,
                ],
            ],
            // Текущие значения признаков: форма начинает с них, а не с пустых.
            'facts' => $doctor->isTherapist() ? $this->facts($patient) : [],
        ]);
    }

    public function store(Request $request, User $patient, Scale $scale, RateScale $rateScale): RedirectResponse
    {
        Gate::authorize('view', $patient);
        abort_unless($this->isDoctorScale($scale), 404);

        /** @var User $doctor */
        $doctor = $request->user();
        $count = count($scale->items);
        $facts = $doctor->isTherapist() ? self::THERAPIST_FACTS : [];

        $validated = $request->validate([
            'scores' => ['required', 'array', "size:{$count}"],
            'scores.*' => ['required', 'integer', 'in:'.implode(',', array_column($scale->options, 'score'))],
            'facts' => [$facts === [] ? 'prohibited' : 'required', 'array:'.implode(',', $facts)],
            ...array_combine(
                array_map(fn (string $code): string => "facts.{$code}", $facts),
                array_fill(0, count($facts), ['required', 'boolean']),
            ),
        ], [
            'scores.required' => 'Оцените все пункты шкалы.',
            'scores.size' => 'Оцените все пункты шкалы.',
            'scores.*' => 'Оцените все пункты шкалы.',
            'facts.*.required' => 'Отметьте, есть ли признак.',
        ]);

        $assessment = $rateScale->handle(
            $doctor,
            $patient,
            $scale,
            array_map(intval(...), array_values($validated['scores'])),
            array_map(boolval(...), $validated['facts'] ?? []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$scale->name} сохранена: {$assessment->total} из {$scale->maxTotal()}, {$assessment->band}."]);

        return to_route('doctor.patients.show', $patient);
    }

    private function isDoctorScale(Scale $scale): bool
    {
        return $scale->rater === ScaleRater::Doctor && $scale->is_active;
    }

    /**
     * @return list<array{code: string, name: string, value: bool|null}>
     */
    private function facts(User $patient): array
    {
        $current = $patient->observations()
            ->with('type')
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Observation $observation): string => $observation->type->code);

        return array_values(ObservationType::query()
            ->whereIn('code', self::THERAPIST_FACTS)
            ->get()
            ->sortBy(fn (ObservationType $type): int|false => array_search($type->code, self::THERAPIST_FACTS, true))
            ->map(fn (ObservationType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'value' => isset($current[$type->code]) ? (bool) $current[$type->code]->value_boolean : null,
            ])
            ->values()
            ->all());
    }
}
