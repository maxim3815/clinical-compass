<?php

namespace App\Support\Adherence;

use App\Enums\DoseDayResult;
use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\RussianTimezone;
use App\Enums\TimeOfDay;
use App\Models\AsNeededDose;
use App\Models\DoseEvent;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\DoseEventPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Блок «Приём препаратов» в карточке пациента у врача: курсы с контрольными периодами
 * и полосой последних дней. Курсы — Course, периоды — CoursePeriod, доля — DoseTally, итог дня —
 * DoseDayResult. Данные берутся тремя запросами к назначениям и двумя к приёмам, сколько бы ни было курсов.
 */
final class PatientAdherence
{
    /**
     * Сколько последних дней курса в полосе.
     */
    public const int STRIP_DAYS = 28;

    /**
     * @return array{
     *     timezone: string|null,
     *     courses: array<int, array<string, mixed>>,
     *     finished: array<int, array<string, mixed>>,
     *     own: array<int, array<string, mixed>>,
     * }
     */
    public static function present(User $patient, CarbonImmutable $today): array
    {
        $timezone = $patient->localTimezone();

        $courses = collect(Course::fromPrescriptions($patient->prescriptions()->with(['drug', 'intakes'])->get(), $timezone))
            ->filter(fn (Course $course): bool => $course->hasStarted($today))
            // Прекращённые и завершённые свои препараты врачу не показываются.
            ->reject(fn (Course $course): bool => $course->source() === PrescriptionSource::Patient && $course->isFinished($today))
            ->values();

        $ids = $courses->flatMap(fn (Course $course): array => $course->versionIds())->all();

        $events = DoseEvent::query()
            ->where('patient_id', $patient->id)
            ->whereIn('prescription_id', $ids)
            ->where('local_date', '<=', $today->toDateString())
            ->orderBy('scheduled_at')
            ->get();

        $asNeeded = AsNeededDose::query()
            ->where('patient_id', $patient->id)
            ->whereIn('prescription_id', $ids)
            ->where('local_date', '<=', $today->toDateString())
            ->orderBy('taken_at')
            ->get();

        $present = fn (Course $course): array => $course->kind() === PrescriptionKind::Regular
            ? self::regular($course, $events->whereIn('prescription_id', $course->versionIds())->values(), $today, $timezone)
            : self::asNeeded($course, $asNeeded->whereIn('prescription_id', $course->versionIds())->values(), $today, $timezone);

        [$doctor, $own] = $courses->partition(fn (Course $course): bool => $course->source() === PrescriptionSource::Doctor);
        [$finished, $current] = $doctor->partition(fn (Course $course): bool => $course->isFinished($today));

        $profileTimezone = $patient->patientProfile->timezone ?? RussianTimezone::Moscow;

        return [
            'timezone' => $profileTimezone === RussianTimezone::Moscow ? null : $profileTimezone->label(),
            'courses' => self::byDrug($current)->map($present)->values()->all(),
            // Сначала то, что закончилось последним.
            'finished' => $finished
                ->sortByDesc(fn (Course $course): array => [$course->lastDay($today)->toDateString(), $course->versions[0]->id])
                ->map($present)
                ->values()
                ->all(),
            'own' => self::byDrug($own)->map($present)->values()->all(),
        ];
    }

    /**
     * Регулярный курс: доля и разбивка по периодам, итог каждого дня полосы и его приёмы.
     *
     * @param  Collection<int, DoseEvent>  $events
     * @return array<string, mixed>
     */
    private static function regular(Course $course, Collection $events, CarbonImmutable $today, string $timezone): array
    {
        $byDate = $events->groupBy(fn (DoseEvent $event): string => $event->local_date->toDateString());
        $lastDay = $course->lastDay($today);

        $periods = array_map(function (CoursePeriod $period) use ($events, $byDate, $lastDay, $today): array {
            $periodEvents = $events->filter(fn (DoseEvent $event): bool => $period->contains($event->local_date));
            $tally = DoseTally::of($periodEvents);

            return [
                ...self::period($period, $today),
                'share' => $tally->share(),
                'taken' => $tally->taken,
                'counted' => $tally->counted(),
                'on_time' => $tally->onTime(),
                'late' => $tally->late,
                'skipped' => self::skipped($tally),
                'missed' => $tally->missed,
                'marked_late' => $tally->markedLate,
                // Дни, в которые приёмов не было: пациент не выбрал время.
                'no_data_days' => self::dates($period->from, $period->to->min($lastDay))
                    ->reject(fn (CarbonImmutable $date): bool => $byDate->has($date->toDateString()))
                    ->count(),
                'by_time_of_day' => self::byTimeOfDay($periodEvents),
            ];
        }, array_reverse(CoursePeriod::forCourse($course, $today)));

        $days = self::stripDates($course, $lastDay)
            ->map(function (CarbonImmutable $date) use ($byDate, $today, $timezone): array {
                /** @var Collection<int, DoseEvent> $dayEvents */
                $dayEvents = $byDate->get($date->toDateString(), collect());
                $result = DoseDayResult::of(DoseTally::of($dayEvents), $date->eq($today));

                return [
                    ...self::day($date, $today),
                    'result' => $result->value,
                    'result_label' => $result->label(),
                    'doses' => $dayEvents->map(fn (DoseEvent $event): array => self::dose($event, $timezone))->values()->all(),
                ];
            })
            ->all();

        return [
            ...self::course($course, $today),
            // Приём без времени не планируется, пока пациент его не выберет.
            'missing_times' => ! $course->isFinished($today)
                && $course->last()->intakes->contains(fn (PrescriptionIntake $intake): bool => $intake->time === null),
            'periods' => $periods,
            'days' => $days,
        ];
    }

    /**
     * Курс «по необходимости»: как часто нужен препарат и сколько дней сверх лимита. Доли нет.
     *
     * @param  Collection<int, AsNeededDose>  $doses
     * @return array<string, mixed>
     */
    private static function asNeeded(Course $course, Collection $doses, CarbonImmutable $today, string $timezone): array
    {
        $byDate = $doses->groupBy(fn (AsNeededDose $dose): string => $dose->local_date->toDateString());
        // Лимит — у версии, которая действовала в этот день. У своего препарата его может не быть.
        $overLimit = function (string $date, int $count) use ($course): bool {
            $limit = $course->versionOn(CarbonImmutable::parse($date, 'UTC'))->max_daily_doses;

            return $limit !== null && $count > $limit;
        };

        $periods = array_map(function (CoursePeriod $period) use ($byDate, $overLimit, $today): array {
            $periodDays = $byDate->filter(fn (Collection $dayDoses, string $date): bool => $period->contains(CarbonImmutable::parse($date, 'UTC')));

            return [
                ...self::period($period, $today),
                'days_with_doses' => $periodDays->count(),
                'doses' => $periodDays->sum(fn (Collection $dayDoses): int => $dayDoses->count()),
                'days_over_limit' => $periodDays->filter(fn (Collection $dayDoses, string $date): bool => $overLimit($date, $dayDoses->count()))->count(),
            ];
        }, array_reverse(CoursePeriod::forCourse($course, $today)));

        $days = self::stripDates($course, $course->lastDay($today))
            ->map(function (CarbonImmutable $date) use ($byDate, $overLimit, $today, $timezone): array {
                /** @var Collection<int, AsNeededDose> $dayDoses */
                $dayDoses = $byDate->get($date->toDateString(), collect());

                return [
                    ...self::day($date, $today),
                    'count' => $dayDoses->count(),
                    'over_limit' => $overLimit($date->toDateString(), $dayDoses->count()),
                    'times' => $dayDoses->map(fn (AsNeededDose $dose): string => DoseEventPresenter::clock($dose->taken_at, $timezone))->values()->all(),
                ];
            })
            ->all();

        return [
            ...self::course($course, $today),
            'missing_times' => false,
            'periods' => $periods,
            'days' => $days,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function course(Course $course, CarbonImmutable $today): array
    {
        $finished = $course->isFinished($today);
        $last = $course->last();

        return [
            'id' => $course->versions[0]->id,
            'drug' => $last->drugName(),
            'kind' => $last->kind->value,
            'kind_label' => $last->kind->label(),
            'source' => $last->source->value,
            'finished' => $finished,
            'started' => self::date($course->start, $today),
            'ended' => $finished ? self::date($course->lastDay($today), $today) : null,
            'day' => $finished ? null : $course->dayNumber($today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function period(CoursePeriod $period, CarbonImmutable $today): array
    {
        return [
            'days' => $period->firstDay === $period->lastDay
                ? 'День '.$period->firstDay
                : 'Дни '.$period->firstDay.'–'.$period->lastDay,
            'dates' => self::range($period->from, $period->to, $today),
            'current' => $period->current,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function day(CarbonImmutable $date, CarbonImmutable $today): array
    {
        return [
            'date' => $date->toDateString(),
            'label' => self::date($date, $today),
            'day_of_month' => $date->day,
            'today' => $date->eq($today),
        ];
    }

    /**
     * Приём дня в полосе: время, статус, причина и комментарий, отметка после закрытия окна.
     *
     * @return array<string, mixed>
     */
    private static function dose(DoseEvent $event, string $timezone): array
    {
        $markedLate = $event->isMarkedAfterWindow() && $event->marked_at !== null;

        return [
            'id' => $event->id,
            'time_of_day' => $event->time_of_day->label(),
            'time' => DoseEventPresenter::clock($event->scheduled_at, $timezone),
            'status' => $event->status->value,
            'status_text' => DoseEventPresenter::statusText($event, $timezone) ?? $event->status->label(),
            'unwell' => $event->skip_reason === DoseSkipReason::Unwell,
            'skip_comment' => $event->skip_comment,
            'marked_late' => $markedLate,
            // Если отметили «Принял» в момент отметки, время уже есть в статусе.
            'marked_at' => $markedLate && ($event->taken_at === null || ! $event->marked_at->eq($event->taken_at))
                ? DoseEventPresenter::moment($event->marked_at, $event->local_date, $timezone)
                : null,
        ];
    }

    /**
     * Не принятые по причинам в порядке причин, только встретившиеся.
     *
     * @return list<array{label: string, count: int, unwell: bool}>
     */
    private static function skipped(DoseTally $tally): array
    {
        $skipped = [];

        foreach (DoseSkipReason::cases() as $reason) {
            if (($tally->skipped[$reason->value] ?? 0) > 0) {
                $skipped[] = [
                    'label' => $reason->label(),
                    'count' => $tally->skipped[$reason->value],
                    'unwell' => $reason === DoseSkipReason::Unwell,
                ];
            }
        }

        return $skipped;
    }

    /**
     * Доля по времени суток, если в периоде приёмы в разное время суток: «утром 95% · вечером 42%».
     *
     * @param  Collection<int, DoseEvent>  $events
     * @return array<int, array{label: string, share: int|null}>|null
     */
    private static function byTimeOfDay(Collection $events): ?array
    {
        $byTime = $events->groupBy(fn (DoseEvent $event): string => $event->time_of_day->value);

        if ($byTime->count() < 2) {
            return null;
        }

        return collect(TimeOfDay::cases())
            ->filter(fn (TimeOfDay $timeOfDay): bool => $byTime->has($timeOfDay->value))
            ->map(fn (TimeOfDay $timeOfDay): array => [
                'label' => mb_lcfirst($timeOfDay->label()),
                'share' => DoseTally::of($byTime->get($timeOfDay->value, collect()))->share(),
            ])
            ->values()
            ->all();
    }

    /**
     * Действующие курсы по препарату, сначала регулярные, потом по дате начала — как назначения выше в карточке.
     *
     * @param  Collection<int, Course>  $courses
     * @return Collection<int, Course>
     */
    private static function byDrug(Collection $courses): Collection
    {
        return $courses->sortBy(fn (Course $course): array => [
            $course->last()->drugName(),
            $course->kind() === PrescriptionKind::Regular ? 0 : 1,
            $course->start->toDateString(),
        ]);
    }

    /**
     * Дни полосы: последние STRIP_DAYS дней курса.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private static function stripDates(Course $course, CarbonImmutable $lastDay): Collection
    {
        return self::dates($course->start->max($lastDay->subDays(self::STRIP_DAYS - 1)), $lastDay);
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private static function dates(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $dates = collect();

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $dates->push($date);
        }

        return $dates;
    }

    /**
     * «20 октября», а не в этом году — «20 октября 2025».
     */
    private static function date(CarbonImmutable $date, CarbonImmutable $today): string
    {
        return $date->isoFormat($date->year === $today->year ? 'D MMMM' : 'D MMMM YYYY');
    }

    /**
     * «8–14 октября», «29 октября – 25 ноября», «29 декабря 2025 – 25 января 2026».
     */
    private static function range(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): string
    {
        return match (true) {
            $from->eq($to) => self::date($from, $today),
            $from->year !== $to->year => $from->isoFormat('D MMMM YYYY').' – '.$to->isoFormat('D MMMM YYYY'),
            $from->month === $to->month => $from->day.'–'.self::date($to, $today),
            default => $from->isoFormat('D MMMM').' – '.self::date($to, $today),
        };
    }
}
