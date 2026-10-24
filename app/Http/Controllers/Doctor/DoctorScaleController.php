<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\RateScale;
use App\Enums\ReferralCategory;
use App\Enums\ScaleRater;
use App\Http\Controllers\Controller;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\Scale;
use App\Models\User;
use App\Support\Referrals\ReferralEngine;
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
            'queueAnswer' => null,
            'scale' => self::form($scale),
            // Текущие значения признаков: форма начинает с них, а не с пустых.
            'facts' => $doctor->isTherapist() ? self::facts($this->currentFacts($patient)) : [],
        ]);
    }

    public function store(Request $request, User $patient, Scale $scale, RateScale $rateScale, ReferralEngine $engine): RedirectResponse
    {
        Gate::authorize('view', $patient);
        abort_unless($this->isDoctorScale($scale), 404);

        /** @var User $doctor */
        $doctor = $request->user();

        $validated = $request->validate(...self::rules($scale, $doctor->isTherapist()));
        // Терапевту HARS может поднять подсказку направления: об этом — в сообщении о сохранении.
        $before = $doctor->isTherapist() ? $engine->suggest($patient)->category : null;

        $assessment = $rateScale->handle(
            $doctor,
            $patient,
            $scale,
            array_map(intval(...), array_values($validated['scores'])),
            array_map(boolval(...), $validated['facts'] ?? []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => self::savedMessage(
            $scale,
            $assessment->total,
            $assessment->band,
            $before,
            $doctor->isTherapist() ? $engine->suggest($patient)->category : null,
        )]);

        return to_route('doctor.patients.show', $patient);
    }

    /**
     * «HARS сохранена: 28 из 56, …». Если подсказка направления изменилась, это видно сразу.
     */
    public static function savedMessage(Scale $scale, int $total, string $band, ?ReferralCategory $before, ?ReferralCategory $after): string
    {
        $message = "{$scale->name} сохранена: {$total} из {$scale->maxTotal()}, {$band}.";

        if ($before === $after) {
            return $message;
        }

        $was = $before === null ? 'раньше направление не подсказывалось' : "было «{$before->label()}»";
        $now = $after === null ? 'направление не подсказывается' : "«{$after->label()}»";

        return "{$message} Подсказка изменилась: {$now}, {$was}.";
    }

    public static function isDoctorScale(Scale $scale): bool
    {
        return $scale->rater === ScaleRater::Doctor && $scale->is_active;
    }

    /**
     * Шкала для формы: пункты с подсказками, варианты, степени и источник.
     *
     * @return array<string, mixed>
     */
    public static function form(Scale $scale): array
    {
        return [
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
        ];
    }

    /**
     * Признаки терапевта с текущими значениями.
     *
     * @param  array<string, bool>  $current  код вида факта => значение
     * @return list<array{code: string, name: string, value: bool|null}>
     */
    public static function facts(array $current): array
    {
        return array_values(ObservationType::query()
            ->whereIn('code', self::THERAPIST_FACTS)
            ->get()
            ->sortBy(fn (ObservationType $type): int|false => array_search($type->code, self::THERAPIST_FACTS, true))
            ->map(fn (ObservationType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'value' => $current[$type->code] ?? null,
            ])
            ->values()
            ->all());
    }

    /**
     * Правила проверки оценок и признаков формы.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function rules(Scale $scale, bool $withFacts): array
    {
        $count = count($scale->items);
        $facts = $withFacts ? self::THERAPIST_FACTS : [];

        return [
            [
                'scores' => ['required', 'array', "size:{$count}"],
                'scores.*' => ['required', 'integer', 'in:'.implode(',', array_column($scale->options, 'score'))],
                'facts' => [$facts === [] ? 'prohibited' : 'required', 'array:'.implode(',', $facts)],
                ...array_combine(
                    array_map(fn (string $code): string => "facts.{$code}", $facts),
                    array_fill(0, count($facts), ['required', 'boolean']),
                ),
            ],
            [
                'scores.required' => 'Оцените все пункты шкалы.',
                'scores.size' => 'Оцените все пункты шкалы.',
                'scores.*' => 'Оцените все пункты шкалы.',
                'facts.*.required' => 'Отметьте, есть ли признак.',
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function currentFacts(User $patient): array
    {
        return $patient->observations()
            ->with('type')
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Observation $observation): string => $observation->type->code)
            ->map(fn (Observation $observation): bool => (bool) $observation->value_boolean)
            ->all();
    }
}
