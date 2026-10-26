<?php

namespace App\Support\Assessments;

use App\Enums\DoctorKind;
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
     * заполнения с ответами на каждый вопрос. Шкалы врача (HARS, HADS) идут после шкал пациента.
     * Шкала с подшкалами — строкой на каждую подшкалу: «HADS, тревога», «HADS, депрессия».
     * Шкала врача другого вида без заполнений не показывается: психиатру не нужна пустая HADS.
     * Где врач шкалу не заполняет ($rating = false, страница направления), пустых шкал врача нет.
     * Заполненные шкалы врача идут перед пустыми: HADS терапевта — перед пустой HARS психиатра.
     *
     * @return array<string, mixed>
     */
    public static function forDoctor(User $patient, ?DoctorKind $viewer = null, bool $rating = true): array
    {
        $assessments = self::assessments($patient, withAnswers: true);
        $scales = Scale::query()->active()->orderByRaw('rater = ?', [ScaleRater::Doctor->value])->orderBy('position')->orderBy('id')->get()
            ->filter(fn (Scale $scale): bool => $scale->rater === ScaleRater::Patient
                || $assessments->contains('scale_id', $scale->id)
                || ($rating && ($viewer === null || $scale->isRatableBy($viewer))))
            ->sortBy(fn (Scale $scale): int => match (true) {
                $scale->rater === ScaleRater::Patient => 0,
                $assessments->contains('scale_id', $scale->id) => 1,
                default => 2,
            });

        return [
            'status' => self::status($patient, 'DD.MM.YYYY'),
            'scales' => $scales
                ->flatMap(function (Scale $scale) use ($assessments): array {
                    $own = $assessments->where('scale_id', $scale->id)->values();

                    // Пустая HADS — одна строка «Не заполнялась», а не две.
                    if (! $scale->hasSubscales() || $own->isEmpty()) {
                        return [self::summary($scale, $own, null)];
                    }

                    return array_map(fn (array $subscale): array => self::summary($scale, $own, $subscale['code']), $scale->subscales ?? []);
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
                        ->map(fn (Assessment $assessment): array => self::presentFilled($assessment))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Шкалы врача, которые этот вид врача заполняет на приёме: кнопки «Заполнить HARS» или
     * «Заполнить HADS».
     *
     * @return list<array{code: string, name: string}>
     */
    public static function ratableScales(?DoctorKind $kind): array
    {
        return array_values(Scale::query()
            ->active()
            ->forDoctors($kind ?? DoctorKind::Psychiatrist)
            ->orderBy('position')
            ->get()
            ->map(fn (Scale $scale): array => ['code' => $scale->code, 'name' => $scale->name])
            ->all());
    }

    /**
     * Действующие опросники пациента для заполнения: в приложении и в очереди у двери терапевта.
     *
     * @return list<array<string, mixed>>
     */
    public static function questionnaire(): array
    {
        return array_values(Scale::query()
            ->active()
            ->forPatients()
            ->orderBy('position')
            ->get()
            ->map(fn (Scale $scale): array => [
                'code' => $scale->code,
                'name' => $scale->name,
                'title' => $scale->patient_title,
                'instruction' => $scale->instruction,
                'options' => $scale->options,
                'items' => array_column($scale->items, 'text'),
                'source' => [
                    'validation' => $scale->source_validation,
                    'url' => $scale->source_url,
                    'license' => $scale->source_license,
                ],
            ])
            ->all());
    }

    /**
     * Заполнение с ответами на каждый вопрос: строка списка заполнений у врача. Годится и для
     * заполнений, ещё не записанных в базу (ответы из очереди): нужны загруженные шкала и ответы.
     *
     * @return array<string, mixed>
     */
    public static function presentFilled(Assessment $assessment): array
    {
        $scale = $assessment->scale;

        return [
            'id' => $assessment->id,
            'scale' => $scale->code,
            'name' => $scale->name,
            // Шкалу врача заполнил врач на приёме: кто именно и кто он — терапевт или психиатр.
            'rated_by' => $assessment->ratedBy?->name,
            'rated_by_role' => $assessment->ratedBy === null ? null : mb_strtolower($assessment->ratedBy->doctorKind()?->specialty() ?? 'врач'),
            'total' => $assessment->total,
            'max' => $scale->maxTotal(),
            'band' => $assessment->band,
            // У HADS суммы и степени по подшкалам вместо общей.
            'subscores' => $assessment->subscores === null ? null : array_map(fn (array $subscore): array => [
                'code' => $subscore['code'],
                'name' => $scale->subscale($subscore['code'])['name'] ?? $subscore['code'],
                'total' => $subscore['total'],
                'max' => $scale->maxTotal($subscore['code']),
                'band' => $subscore['band'],
            ], $assessment->subscores),
            'answers' => $assessment->answers
                ->map(function (AssessmentAnswer $answer) use ($scale): array {
                    $item = $scale->items[$answer->item - 1] ?? [];

                    return [
                        'item' => $answer->item,
                        'text' => $item['text'] ?? "Вопрос {$answer->item}",
                        'hint' => $item['hint'] ?? null,
                        // Подшкала вопроса: «тревога» или «депрессия» у HADS.
                        'group' => isset($item['subscale']) ? ($scale->subscale($item['subscale'])['name'] ?? null) : null,
                        'score' => $answer->score,
                        'label' => $scale->optionLabel($answer->score, $answer->item),
                        'highlight' => $item['highlight'] ?? false,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Строка шкалы в «Самочувствии»: последняя сумма со степенью, прошлая и первая, точки графика.
     * С кодом подшкалы — то же по подшкале.
     *
     * @param  BaseCollection<int, Assessment>  $own  заполнения шкалы по порядку
     * @return array<string, mixed>
     */
    private static function summary(Scale $scale, BaseCollection $own, ?string $subscale): array
    {
        $latest = $own->last();
        $previous = $own->count() > 1 ? $own[$own->count() - 2] : null;
        // Ответ на терапию оценивают от первого заполнения.
        $first = $own->count() > 2 ? $own->first() : null;
        $bands = $subscale === null ? $scale->bands : ($scale->subscale($subscale)['bands'] ?? []);

        return [
            'code' => $subscale === null ? $scale->code : "{$scale->code}_{$subscale}",
            'scale' => $scale->code,
            'scale_name' => $scale->name,
            'name' => $subscale === null ? $scale->name : $scale->subscaleName($subscale),
            'title' => $scale->title,
            'rater' => $scale->rater->value,
            // Кто заполняет шкалу врача: «HADS заполняет терапевт на приёме».
            'doctor_kind' => $scale->doctor_kind?->value,
            'max' => $scale->maxTotal($subscale),
            // Границы степеней — линии на графике врача.
            'thresholds' => array_map(fn (array $band): int => $band['min'], array_slice($bands, 1)),
            'latest' => $latest === null ? null : [
                'total' => self::total($latest, $subscale),
                'band' => $subscale === null ? $latest->band : ($latest->subscore($subscale)['band'] ?? ''),
                'date' => DisplayTime::formatDate($latest->local_date),
            ],
            'previous' => $previous === null ? null : [
                'total' => self::total($previous, $subscale),
                'date' => DisplayTime::formatDate($previous->local_date),
            ],
            'first' => $first === null ? null : [
                'total' => self::total($first, $subscale),
                'date' => DisplayTime::formatDate($first->local_date),
            ],
            'points' => self::points($own, 'DD.MM.YYYY', $subscale),
            'source' => self::source($scale),
        ];
    }

    /**
     * Сумма заполнения или его подшкалы.
     */
    private static function total(Assessment $assessment, ?string $subscale): int
    {
        return $subscale === null ? $assessment->total : ($assessment->subscore($subscale)['total'] ?? 0);
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
            ->with($withAnswers ? ['scale', 'answers', 'ratedBy.doctorProfile'] : ['scale'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Точки графика по порядку заполнения: дата для расстояния по оси (ISO), подпись, короткая
     * подпись оси и сумма (с кодом подшкалы — сумма подшкалы).
     *
     * @param  BaseCollection<int, Assessment>  $assessments
     * @return array<int, array{id: int, iso: string, date: string, short: string, total: int}>
     */
    private static function points(BaseCollection $assessments, string $format, ?string $subscale = null): array
    {
        return $assessments
            ->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                'iso' => $assessment->local_date->toDateString(),
                'date' => self::date($assessment->local_date, $format),
                'short' => $assessment->local_date->format('d.m'),
                'total' => self::total($assessment, $subscale),
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
