<?php

namespace App\Support\Assessments;

use App\Enums\ScaleRater;
use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\Scale;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Опросники пациента: пора ли заполнять по календарю, суммы для графика у пациента и блок
 * «Самочувствие» в карточке у врача. Календарь считается от начала наблюдения у текущего врача.
 */
final class PatientAssessments
{
    /**
     * Пора ли заполнить опросники, когда заполнял последний раз и когда следующий срок.
     * null — опросников нет или у пациента нет лечащего врача: отвечать некому.
     *
     * @return array{due: bool, due_since: string|null, last: string|null, next: string|null}|null
     */
    public static function status(User $patient, string $format = 'D MMMM'): ?array
    {
        $scales = self::scales();
        $start = self::start($patient);

        if ($scales->isEmpty() || $start === null) {
            return null;
        }

        $filled = self::filledDates($patient);
        $today = $patient->localToday();
        $open = [];
        $next = [];

        foreach ($scales as $scale) {
            $dates = $filled[$scale->id] ?? [];
            $calendar = $scale->calendar();
            $due = $calendar->openDue($start, $today, $dates);

            if ($due !== null) {
                $open[] = $due;
            }

            $next[] = $calendar->nextDue($start, $today, $dates);
        }

        $all = array_merge(...array_values($filled));
        $last = $all === [] ? null : max($all);

        return [
            'due' => $open !== [],
            'due_since' => $open === [] ? null : self::date(min($open), $format),
            'last' => $last === null ? null : self::date($last, $format),
            'next' => $next === [] ? null : self::date(min($next), $format),
        ];
    }

    /**
     * Суммы по каждому опроснику для графика пациента: без степеней, интерпретация только у врача.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forPatient(User $patient): array
    {
        $assessments = self::assessments($patient);

        return self::scales()
            ->map(fn (Scale $scale): array => [
                'code' => $scale->code,
                'name' => $scale->name,
                'title' => $scale->patient_title,
                'max' => $scale->maxTotal(),
                'points' => self::points($assessments->where('scale_id', $scale->id), 'D MMMM'),
                'source' => self::source($scale),
            ])
            ->values()
            ->all();
    }

    /**
     * Блок «Самочувствие» в карточке у врача: последние суммы со степенью и изменением, график,
     * заполнения с ответами на каждый вопрос. Шкалы врача (HARS) идут после шкал пациента.
     *
     * @return array<string, mixed>
     */
    public static function forDoctor(User $patient): array
    {
        $assessments = self::assessments($patient, withAnswers: true);
        $scales = Scale::query()->active()->orderByRaw('rater = ?', [ScaleRater::Doctor->value])->orderBy('position')->orderBy('id')->get();

        return [
            'status' => self::status($patient, 'DD.MM.YYYY'),
            'scales' => $scales
                ->map(function (Scale $scale) use ($assessments): array {
                    $own = $assessments->where('scale_id', $scale->id)->values();
                    $latest = $own->last();
                    $previous = $own->count() > 1 ? $own[$own->count() - 2] : null;
                    // Ответ на терапию оценивают от первого заполнения.
                    $first = $own->count() > 2 ? $own->first() : null;

                    return [
                        'code' => $scale->code,
                        'name' => $scale->name,
                        'title' => $scale->title,
                        'rater' => $scale->rater->value,
                        'max' => $scale->maxTotal(),
                        // Границы степеней — линии на графике врача.
                        'thresholds' => array_map(fn (array $band): int => $band['min'], array_slice($scale->bands, 1)),
                        'latest' => $latest === null ? null : [
                            'total' => $latest->total,
                            'band' => $latest->band,
                            'date' => DisplayTime::formatDate($latest->local_date),
                        ],
                        'previous' => $previous === null ? null : [
                            'total' => $previous->total,
                            'date' => DisplayTime::formatDate($previous->local_date),
                        ],
                        'first' => $first === null ? null : [
                            'total' => $first->total,
                            'date' => DisplayTime::formatDate($first->local_date),
                        ],
                        'points' => self::points($own, 'DD.MM.YYYY'),
                        'source' => self::source($scale),
                    ];
                })
                ->values()
                ->all(),
            // Заполнения по отправкам, сначала последние: опросники одной отправки — одна строка.
            'fillings' => $assessments
                ->groupBy(fn (Assessment $assessment): int => $assessment->submitted_at->getTimestamp())
                ->sortKeysDesc()
                ->map(fn (Collection $group): array => [
                    'submitted_at' => DisplayTime::format($group->firstOrFail()->submitted_at),
                    'assessments' => $group
                        ->sortBy(fn (Assessment $assessment): int => $assessment->scale->position)
                        ->map(fn (Assessment $assessment): array => [
                            'id' => $assessment->id,
                            'scale' => $assessment->scale->code,
                            'name' => $assessment->scale->name,
                            // Шкалу врача заполнил врач на приёме: кто именно.
                            'rated_by' => $assessment->ratedBy?->name,
                            'total' => $assessment->total,
                            'max' => $assessment->scale->maxTotal(),
                            'band' => $assessment->band,
                            'answers' => $assessment->answers
                                ->map(fn (AssessmentAnswer $answer): array => [
                                    'item' => $answer->item,
                                    'text' => $assessment->scale->items[$answer->item - 1]['text'] ?? "Вопрос {$answer->item}",
                                    'hint' => $assessment->scale->items[$answer->item - 1]['hint'] ?? null,
                                    'score' => $answer->score,
                                    'label' => $assessment->scale->optionLabel($answer->score),
                                    'highlight' => $assessment->scale->items[$answer->item - 1]['highlight'] ?? false,
                                ])
                                ->values()
                                ->all(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Начало наблюдения у текущего врача — местная дата пациента.
     */
    public static function start(User $patient): ?CarbonImmutable
    {
        $care = $patient->activeCareRelationship()->first();

        return $care === null
            ? null
            : CarbonImmutable::parse($care->started_at->setTimezone($patient->localTimezone())->toDateString(), 'UTC');
    }

    /**
     * @return Collection<int, Scale>
     */
    private static function scales(): Collection
    {
        return Scale::query()->active()->forPatients()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Даты заполнений по шкале.
     *
     * @return array<int, list<CarbonImmutable>>
     */
    private static function filledDates(User $patient): array
    {
        $dates = [];

        foreach (Assessment::query()->where('patient_id', $patient->id)->get(['scale_id', 'local_date']) as $assessment) {
            $dates[$assessment->scale_id][] = $assessment->local_date;
        }

        return $dates;
    }

    /**
     * @return Collection<int, Assessment>
     */
    private static function assessments(User $patient, bool $withAnswers = false): Collection
    {
        return Assessment::query()
            ->where('patient_id', $patient->id)
            ->with($withAnswers ? ['scale', 'answers', 'ratedBy'] : ['scale'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Точки графика по порядку заполнения: дата для расстояния по оси (ISO), подпись, короткая
     * подпись оси и сумма.
     *
     * @param  BaseCollection<int, Assessment>  $assessments
     * @return array<int, array{id: int, iso: string, date: string, short: string, total: int}>
     */
    private static function points(BaseCollection $assessments, string $format): array
    {
        return $assessments
            ->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                'iso' => $assessment->local_date->toDateString(),
                'date' => self::date($assessment->local_date, $format),
                'short' => $assessment->local_date->format('d.m'),
                'total' => $assessment->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{original: string, validation: string, url: string, license: string, note: string|null}
     */
    private static function source(Scale $scale): array
    {
        return [
            'original' => $scale->source_original,
            'validation' => $scale->source_validation,
            'url' => $scale->source_url,
            'license' => $scale->source_license,
            'note' => $scale->source_note,
        ];
    }

    private static function date(CarbonImmutable $date, string $format): string
    {
        return $date->isoFormat($format);
    }
}
