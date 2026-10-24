<?php

namespace App\Support\Adherence;

use App\Enums\DoseDayResult;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Models\AsNeededDose;
use App\Models\DoseEvent;
use App\Models\User;
use App\Support\DoseEventPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Календарь приёма у пациента: месяц, где цвет дня — итог всех его плановых приёмов за день,
 * и по назначениям врача, и по своим препаратам, в том числе по завершённым курсам. Итог дня
 * считается как у врача (DoseDayResult), но «вовремя» и «поздно» у пациента не различаются.
 * Приёмы «по необходимости» на итог не влияют, они есть только в составе дня.
 */
final class IntakeCalendar
{
    /**
     * «В октябре вы приняли …»: в предложном падеже Carbon месяцы не склоняет.
     */
    private const array IN_MONTH = [
        1 => 'январе', 'феврале', 'марте', 'апреле', 'мае', 'июне',
        'июле', 'августе', 'сентябре', 'октябре', 'ноябре', 'декабре',
    ];

    /**
     * @param  string|null  $month  Месяц «2026-10». Неверный или вне истории пациента — ближайший допустимый.
     * @return array<string, mixed>
     */
    public static function present(User $patient, ?string $month, CarbonImmutable $today): array
    {
        $timezone = $patient->localTimezone();
        $female = $patient->isFemalePatient();
        $current = $today->startOfMonth();
        $first = self::firstMonth($patient) ?? $current;
        $start = self::month($month)?->max($first)->min($current) ?? $current;
        $end = $start->endOfMonth()->startOfDay();
        $last = $end->min($today);

        $events = $patient->doseEvents()
            ->whereBetween('local_date', [$start->toDateString(), $last->toDateString()])
            ->with(['prescription.drug', 'prescription.strength'])
            ->orderBy('scheduled_at')
            ->get();
        $asNeeded = $patient->asNeededDoses()
            ->whereBetween('local_date', [$start->toDateString(), $last->toDateString()])
            ->with('prescription.drug')
            ->orderBy('taken_at')
            ->get();

        $eventsByDate = $events->groupBy(fn (DoseEvent $event): string => $event->local_date->toDateString());
        $asNeededByDate = $asNeeded->groupBy(fn (AsNeededDose $dose): string => $dose->local_date->toDateString());
        $tally = DoseTally::of($events);

        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            /** @var Collection<int, DoseEvent> $dayEvents */
            $dayEvents = $eventsByDate->get($date->toDateString(), collect());
            /** @var Collection<int, AsNeededDose> $dayAsNeeded */
            $dayAsNeeded = $asNeededByDate->get($date->toDateString(), collect());

            $days[] = self::day($date, $today, $dayEvents, $dayAsNeeded, $timezone, $female);
        }

        return [
            'month' => $start->format('Y-m'),
            'title' => mb_ucfirst($start->isoFormat('MMMM YYYY')),
            'in_month' => self::IN_MONTH[$start->month].($start->year === $today->year ? '' : ' '.$start->year),
            'previous' => $start->gt($first) ? $start->subMonth()->format('Y-m') : null,
            'previous_title' => $start->gt($first) ? self::monthName($start->subMonth(), $today) : null,
            'next' => $start->lt($current) ? $start->addMonth()->format('Y-m') : null,
            'next_title' => $start->lt($current) ? self::monthName($start->addMonth(), $today) : null,
            // Сколько клеток пропустить перед первым числом: неделя начинается с понедельника.
            'offset' => $start->dayOfWeekIso - 1,
            'taken' => $tally->taken,
            'counted' => $tally->counted(),
            'days' => $days,
        ];
    }

    /**
     * Есть ли у пациента что показать в календаре: плановые приёмы или приёмы «по необходимости».
     */
    public static function hasHistory(User $patient): bool
    {
        return self::firstMonth($patient) !== null;
    }

    /**
     * Месяц первого приёма пациента, планового или «по необходимости».
     */
    private static function firstMonth(User $patient): ?CarbonImmutable
    {
        $first = collect([
            $patient->doseEvents()->min('local_date'),
            $patient->asNeededDoses()->min('local_date'),
        ])->filter()->min();

        return $first === null ? null : CarbonImmutable::parse($first, 'UTC')->startOfMonth();
    }

    /**
     * Подпись стрелки: «Сентябрь», в другом году — «Декабрь 2025».
     */
    private static function monthName(CarbonImmutable $month, CarbonImmutable $today): string
    {
        return mb_ucfirst($month->isoFormat($month->year === $today->year ? 'MMMM' : 'MMMM YYYY'));
    }

    private static function month(?string $month): ?CarbonImmutable
    {
        if ($month === null || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $parts) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, 'UTC');
    }

    /**
     * @param  Collection<int, DoseEvent>  $events
     * @param  Collection<int, AsNeededDose>  $asNeeded
     * @return array<string, mixed>
     */
    private static function day(CarbonImmutable $date, CarbonImmutable $today, Collection $events, Collection $asNeeded, string $timezone, bool $female): array
    {
        $future = $date->gt($today);

        return [
            'date' => $date->toDateString(),
            'day' => $date->day,
            'label' => mb_ucfirst($date->isoFormat($date->year === $today->year ? 'dddd, D MMMM' : 'dddd, D MMMM YYYY')),
            'today' => $date->eq($today),
            'yesterday' => $date->eq($today->subDay()),
            'future' => $future,
            'result' => $future ? null : self::result(DoseDayResult::of(DoseTally::of($events), $date->eq($today))),
            // По плановому времени, на одно время — по препарату, как на «Сегодня».
            'doses' => $events
                ->sortBy(fn (DoseEvent $event): array => [$event->scheduled_at->getTimestamp(), $event->prescription->drugName()])
                ->map(fn (DoseEvent $event): array => self::dose($event, $timezone, $female))
                ->values()
                ->all(),
            'as_needed' => self::asNeeded($asNeeded, $timezone),
            'unwell' => $events->contains(fn (DoseEvent $event): bool => $event->status === DoseEventStatus::Skipped
                && $event->skip_reason === DoseSkipReason::Unwell),
            // Отметить можно только сегодня и вчера: тогда из календаря есть ссылка на «Сегодня».
            'can_mark' => $date->gte($today->subDay()) && ! $future
                && $events->contains(fn (DoseEvent $event): bool => ! $event->status->isMarked()),
        ];
    }

    /**
     * Итог дня для пациента: «вовремя» и «поздно» — одно «всё принято».
     */
    private static function result(DoseDayResult $result): string
    {
        return match ($result) {
            DoseDayResult::OnTime, DoseDayResult::Late => 'taken',
            DoseDayResult::Partial => 'partial',
            DoseDayResult::None => 'none',
            DoseDayResult::Pending => 'pending',
            DoseDayResult::NoData => 'no_data',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function dose(DoseEvent $event, string $timezone, bool $female): array
    {
        $prescription = $event->prescription;

        return [
            'id' => $event->id,
            'time' => DoseEventPresenter::clock($event->scheduled_at, $timezone),
            'drug' => $prescription->drugName(),
            'dose' => $prescription->doseLabel($event->tablets),
            'status' => $event->status->value,
            // Как на «Сегодня»: пропущенный приём — «Нет отметки».
            'status_text' => match ($event->status) {
                DoseEventStatus::Missed => 'Нет отметки',
                DoseEventStatus::Scheduled => 'Ещё не отмечен',
                default => DoseEventPresenter::statusText($event, $timezone, $female, late: false) ?? $event->status->label(),
            },
            'skip_comment' => $event->skip_comment,
        ];
    }

    /**
     * Приёмы «по необходимости» за день по препарату. Лимит — у назначения, по которому записан
     * приём, а приёмы считаются по препарату, как предупреждение на «Сегодня».
     *
     * @param  Collection<int, AsNeededDose>  $doses
     * @return array<int, array{drug: string, count: int, times: array<int, string>, over_limit: int|null}>
     */
    private static function asNeeded(Collection $doses, string $timezone): array
    {
        return $doses
            ->groupBy(fn (AsNeededDose $dose): string => $dose->prescription->drugKey())
            ->map(function (Collection $drugDoses) use ($timezone): array {
                /** @var Collection<int, AsNeededDose> $drugDoses */
                $limit = $drugDoses->map(fn (AsNeededDose $dose): ?int => $dose->prescription->max_daily_doses)->filter()->min();

                return [
                    'drug' => $drugDoses->first()->prescription->drugName(),
                    'count' => $drugDoses->count(),
                    'times' => $drugDoses->map(fn (AsNeededDose $dose): string => DoseEventPresenter::clock($dose->taken_at, $timezone))->values()->all(),
                    // Сколько можно в сутки, если приёмов больше; иначе null.
                    'over_limit' => $limit !== null && $drugDoses->count() > $limit ? (int) $limit : null,
                ];
            })
            ->sortBy('drug')
            ->values()
            ->all();
    }
}
