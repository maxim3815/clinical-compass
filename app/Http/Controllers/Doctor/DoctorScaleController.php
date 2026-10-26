<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\RateScale;
use App\Enums\ReferralCategory;
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
 * Шкала врача на приёме: форма и сохранение. Психиатр заполняет HARS, терапевт — HADS и вместе
 * с ней отмечает признаки, которых нет ни в одной шкале: по ним подсказка направления ставит
 * «обязательно». Шкала чужого вида врача отвечает 404.
 *
 * @phpstan-import-type Subscore from Scale
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

        /** @var User $doctor */
        $doctor = $request->user();
        abort_unless($scale->isRatableBy($doctor->doctorKind()), 404);

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

        /** @var User $doctor */
        $doctor = $request->user();
        abort_unless($scale->isRatableBy($doctor->doctorKind()), 404);

        $validated = $request->validate(...self::rules($scale, $doctor->isTherapist()));
        // Терапевту HADS может поднять подсказку направления: об этом — в сообщении о сохранении.
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
            $assessment->subscores,
            $before,
            $doctor->isTherapist() ? $engine->suggest($patient)->category : null,
        )]);

        return to_route('doctor.patients.show', $patient);
    }

    /**
     * «HARS сохранена: 28 из 56, …», у HADS — суммы подшкал без степеней, они рядом в карточке:
     * «HADS сохранена: тревога 12, депрессия 6 из 21». Если подсказка направления изменилась,
     * это видно сразу.
     *
     * @param  list<Subscore>|null  $subscores
     */
    public static function savedMessage(Scale $scale, int $total, string $band, ?array $subscores, ?ReferralCategory $before, ?ReferralCategory $after): string
    {
        $result = $subscores === null
            ? "{$total} из {$scale->maxTotal()}, {$band}"
            : implode(', ', array_map(
                fn (array $subscore): string => ($scale->subscale($subscore['code'])['name'] ?? $subscore['code'])." {$subscore['total']}",
                $subscores,
            )).' из '.$scale->maxTotal($subscores[0]['code'] ?? null);
        $message = "{$scale->name} сохранена: {$result}.";

        if ($before === $after) {
            return $message;
        }

        $was = $before === null ? 'раньше направление не подсказывалось' : "было «{$before->label()}»";
        $now = $after === null ? 'направление не подсказывается' : "«{$after->label()}»";

        return "{$message} Подсказка изменилась: {$now}, {$was}.";
    }

    /**
     * Шкала для формы: пункты с подсказками, варианты (общие или свои у пункта), подшкалы,
     * степени и источник.
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
            'prompt' => $scale->prompt,
            'options' => $scale->options,
            'items' => array_map(fn (array $item): array => [
                'text' => $item['text'],
                'hint' => $item['hint'] ?? null,
                'options' => $item['options'] ?? null,
                'subscale' => $item['subscale'] ?? null,
            ], $scale->items),
            'max' => $scale->maxTotal(),
            'bands' => $scale->bands,
            'subscales' => $scale->hasSubscales()
                ? array_map(fn (array $subscale): array => [
                    'code' => $subscale['code'],
                    'name' => $subscale['name'],
                    'max' => $scale->maxTotal($subscale['code']),
                    'bands' => $subscale['bands'],
                ], $scale->subscales ?? [])
                : null,
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
        // Баллы каждого пункта — из его вариантов: у HADS они свои у пункта.
        $scores = [];

        foreach (array_keys($scale->items) as $index) {
            $scores["scores.{$index}"] = ['required', 'integer', 'in:'.implode(',', array_column($scale->optionsFor($index + 1), 'score'))];
        }

        return [
            [
                'scores' => ['required', 'list', "size:{$count}"],
                ...$scores,
                'facts' => [$facts === [] ? 'prohibited' : 'required', 'array:'.implode(',', $facts)],
                ...array_combine(
                    array_map(fn (string $code): string => "facts.{$code}", $facts),
                    array_fill(0, count($facts), ['required', 'boolean']),
                ),
            ],
            [
                'scores.required' => 'Оцените все пункты шкалы.',
                'scores.list' => 'Оцените все пункты шкалы.',
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
