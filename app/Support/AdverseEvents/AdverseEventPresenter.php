<?php

namespace App\Support\AdverseEvents;

use App\Actions\ResolveAdverseEvent;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionSource;
use App\Models\AdverseEvent;
use App\Models\CareRelationship;
use App\Models\User;
use App\Support\Adherence\Course;
use App\Support\DisplayTime;
use App\Support\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Побочные эффекты для пациента («Вы сообщали»), для врача (лента в карточке) и для очереди
 * «Требуют внимания». Препарат к записи не привязан: врач видит, на фоне каких курсов начался
 * симптом и какой это был день курса, а исход — из назначений после записи.
 */
final class AdverseEventPresenter
{
    /**
     * Прошедшие симптомы пациент видит ещё столько дней: дальше список нужен только врачу.
     */
    public const int PATIENT_RESOLVED_DAYS = 30;

    /**
     * Список пациента: непрошедшие и недавно прошедшие, сначала новые.
     *
     * @return list<array{id: int, name: string, severity: string, period: string, resolved: bool, undoable: bool, by_doctor: bool}>
     */
    public static function forPatient(User $patient): array
    {
        $since = $patient->localToday()->subDays(self::PATIENT_RESOLVED_DAYS)->toDateString();

        return array_values($patient->adverseEvents()
            ->where(fn (Builder $query) => $query->whereNull('resolved_on')->orWhere('resolved_on', '>=', $since))
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AdverseEvent $event): array => [
                'id' => $event->id,
                'name' => $event->name,
                'severity' => $event->severity->shortLabel(),
                'period' => self::patientPeriod($event),
                'resolved' => $event->resolved_on !== null,
                // Отмечено сегодня: «Не прошло» ещё можно нажать.
                'undoable' => ResolveAdverseEvent::undoable($patient, $event),
                'by_doctor' => $event->isByDoctor(),
            ])
            ->all());
    }

    /**
     * Лента в карточке врача, сначала новые.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDoctor(User $patient): array
    {
        $timezone = $patient->localTimezone();
        $courses = Course::fromPrescriptions($patient->prescriptions()->with('drug')->get(), $timezone);

        return array_values($patient->adverseEvents()
            ->with(['reportedBy', 'reviewedBy', 'alert'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (AdverseEvent $event) use ($courses, $timezone): array {
                $background = array_values(array_filter($courses, fn (Course $course): bool => $course->start->lte($event->started_on)
                    && ($course->end === null || $course->end->gte($event->started_on))));
                $alertOpen = $event->alert !== null && $event->alert->closed_at === null;

                return [
                    'id' => $event->id,
                    // Термин врачу, слова пациента рядом. У «другого» только слова пациента.
                    'term' => $event->term,
                    'name' => $event->name,
                    'level' => $event->level?->value,
                    'severity' => $event->severity->label(),
                    'severity_short' => $event->severity->shortLabel(),
                    'started_on' => $event->started_on->format('d.m.Y'),
                    'reported_at' => DisplayTime::format($event->created_at),
                    'by_doctor' => $event->isByDoctor() ? PersonName::short($event->reportedBy->name) : null,
                    'medications' => array_map(fn (Course $course): array => [
                        'drug' => $course->last()->drugName(),
                        'day' => $course->dayNumber($event->started_on),
                    ], $background),
                    'resolved' => $event->resolved_on === null ? null : [
                        'on' => $event->resolved_on->format('d.m.Y'),
                        'days' => (int) $event->started_on->diffInDays($event->resolved_on) + 1,
                    ],
                    'outcomes' => array_values(array_filter(array_map(
                        fn (Course $course): ?string => self::outcome($course, $event->created_at, $timezone),
                        $background,
                    ))),
                    'reviewed' => $event->reviewed_at === null || $event->isByDoctor() ? null : [
                        'at' => DisplayTime::format($event->reviewed_at),
                        'by' => $event->reviewedBy === null ? null : PersonName::short($event->reviewedBy->name),
                    ],
                    'awaits_review' => $event->reviewed_at === null && ! $alertOpen,
                    'alert_open' => $alertOpen,
                    'comment' => $event->comment,
                ];
            })
            ->all());
    }

    /**
     * Непросмотренные побочные эффекты без открытого алерта по пациентам: плашка в очереди
     * «Требуют внимания». Срочные с алертом видны плашкой алерта.
     *
     * @param  array<int>  $patientIds
     * @return array<int, list<string>> id пациента => «Сонливость, сильно» (термин, у «другого» слова пациента), сначала новые
     */
    public static function awaitingByPatient(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        return self::awaiting(AdverseEvent::query()->whereIn('patient_id', $patientIds))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['patient_id', 'name', 'term', 'severity'])
            ->groupBy('patient_id')
            ->map(fn ($events): array => array_values($events
                ->map(fn (AdverseEvent $event): string => ($event->term ?? $event->name).', '.$event->severity->shortLabel())
                ->unique()
                ->all()))
            ->all();
    }

    /**
     * Пациенты врача с непросмотренными побочными эффектами: они в счётчике у «Пациентов».
     *
     * @return list<int>
     */
    public static function patientIds(User $doctor): array
    {
        return array_values(self::awaiting(AdverseEvent::query()->whereIn('patient_id', CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->select('patient_id')))
            ->distinct()
            ->pluck('patient_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  Builder<AdverseEvent>  $query
     * @return Builder<AdverseEvent>
     */
    private static function awaiting(Builder $query): Builder
    {
        return $query->awaitingReview()->where(fn (Builder $query) => $query
            ->whereNull('alert_id')
            ->orWhereHas('alert', fn (Builder $alert) => $alert->whereNotNull('closed_at')));
    }

    /**
     * «с 21 октября» или «21–25 октября» у прошедшего.
     */
    private static function patientPeriod(AdverseEvent $event): string
    {
        $start = $event->started_on;
        $end = $event->resolved_on;

        if ($end === null) {
            return 'с '.$start->isoFormat('D MMMM');
        }

        if ($start->isSameDay($end)) {
            return $start->isoFormat('D MMMM');
        }

        return ($start->isSameMonth($end) ? $start->format('j') : $start->isoFormat('D MMMM')).'–'.$end->isoFormat('D MMMM');
    }

    /**
     * Что стало с курсом после записи: врач изменил или отменил назначение, пациент прекратил свой
     * препарат. Отдельного статуса у записи нет, исход берётся из назначений.
     */
    private static function outcome(Course $course, CarbonImmutable $reportedAt, string $timezone): ?string
    {
        $drug = $course->last()->drugName();
        $last = $course->last();

        if ($last->close_reason === PrescriptionCloseReason::Cancelled && $last->closed_at?->gte($reportedAt)) {
            $on = $last->closed_at->setTimezone($timezone)->format('d.m.Y');

            if ($course->source() === PrescriptionSource::Patient) {
                return "{$drug}: пациент прекратил приём {$on}";
            }

            return "{$drug}: отменено {$on}".($last->cancel_reason === null ? '' : ': '.mb_strtolower($last->cancel_reason->label()));
        }

        foreach ($course->versions as $version) {
            if ($version->close_reason === PrescriptionCloseReason::Changed && $version->closed_at?->gte($reportedAt)) {
                return "{$drug}: назначение изменено {$version->closed_at->setTimezone($timezone)->format('d.m.Y')}";
            }
        }

        return null;
    }
}
