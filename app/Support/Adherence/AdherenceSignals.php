<?php

namespace App\Support\Adherence;

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сигналы о приверженности для врача: доля принятых за последние дни для списка пациентов,
 * пропуски подряд и счётчик пациентов с новыми пропусками у пункта меню «Пациенты».
 *
 * Считается только приём регулярных назначений врача: свои препараты пациента и приёмы
 * «по необходимости» не входят. Правила учёта — как в DoseTally: учтённые приёмы — принятые,
 * не принятые и пропущенные, запланированный не учитывается. Число запросов не зависит от числа пациентов.
 */
final class AdherenceSignals
{
    /**
     * За сколько последних местных дней пациента, включая сегодня, считается доля.
     */
    public const int DAYS = 7;

    /**
     * Учтённые приёмы.
     */
    private const array COUNTED = [
        DoseEventStatus::Taken->value,
        DoseEventStatus::Skipped->value,
        DoseEventStatus::Missed->value,
    ];

    /**
     * Подзапрос доли принятых за DAYS дней для пациента из внешнего запроса: по нему список
     * пациентов и сортируется. Процент с округлением вниз, как DoseTally::share(); без учтённых
     * приёмов — null.
     */
    public static function shareQuery(string $patientColumn): Builder
    {
        // Сегодня по часовому поясу пациента, как User::localToday().
        $today = "(CAST(? AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE COALESCE(patient_profiles.timezone, ?))::date";
        $bindings = [CarbonImmutable::now()->utc()->format('Y-m-d H:i:s'), DisplayTime::TIMEZONE];

        return DB::table('dose_events')
            ->join('prescriptions', 'prescriptions.id', '=', 'dose_events.prescription_id')
            ->leftJoin('patient_profiles', 'patient_profiles.user_id', '=', 'dose_events.patient_id')
            ->selectRaw('(100 * count(*) FILTER (WHERE dose_events.status = ?)) / NULLIF(count(*), 0)', [DoseEventStatus::Taken->value])
            ->whereColumn('dose_events.patient_id', $patientColumn)
            ->where('prescriptions.source', PrescriptionSource::Doctor->value)
            ->whereIn('dose_events.status', self::COUNTED)
            ->whereRaw("dose_events.local_date > {$today} - ".self::DAYS, $bindings)
            ->whereRaw("dose_events.local_date <= {$today}", $bindings);
    }

    /**
     * Сигнал пациента для его карточки.
     */
    public static function forPatient(User $patient): AdherenceSignal
    {
        $share = DB::table('users')
            ->where('users.id', $patient->id)
            ->selectSub(self::shareQuery('users.id'), 'share')
            ->value('share');

        return new AdherenceSignal(
            $share === null ? null : (int) $share,
            self::consecutiveMisses(collect([$patient]))[$patient->id] ?? 0,
        );
    }

    /**
     * Больше всего непринятых приёмов подряд в конце одного действующего курса — по плановому
     * времени, среди учтённых приёмов. Курс — со всеми версиями, как в карточке (Course).
     *
     * @param  Collection<int, User>  $patients
     * @return array<int, int> id пациента => число; пациента без пропусков в конце курса нет
     */
    public static function consecutiveMisses(Collection $patients): array
    {
        if ($patients->isEmpty()) {
            return [];
        }

        $prescriptions = Prescription::query()
            ->whereIn('patient_id', $patients->map(fn (User $patient): int => $patient->id))
            ->where('source', PrescriptionSource::Doctor)
            ->where('kind', PrescriptionKind::Regular)
            ->get()
            ->groupBy('patient_id');

        // Версия назначения => первая версия её курса, первая версия => пациент.
        $courseOf = [];
        $patientOf = [];

        foreach ($patients as $patient) {
            $today = $patient->localToday();

            foreach (Course::fromPrescriptions($prescriptions->get($patient->id, collect()), $patient->localTimezone()) as $course) {
                if (! $course->hasStarted($today) || $course->isFinished($today)) {
                    continue;
                }

                foreach ($course->versionIds() as $id) {
                    $courseOf[$id] = $course->versions[0]->id;
                }

                $patientOf[$course->versions[0]->id] = $patient->id;
            }
        }

        if ($courseOf === []) {
            return [];
        }

        // Приём считается в серии, если после него по плановому времени в курсе нет принятых.
        $rows = DB::select(<<<'SQL'
            SELECT course_id, count(*) AS misses
            FROM (
                SELECT courses.course_id,
                    count(*) FILTER (WHERE dose_events.status = ?) OVER (
                        PARTITION BY courses.course_id
                        ORDER BY dose_events.scheduled_at DESC, dose_events.id DESC
                    ) AS taken_since
                FROM dose_events
                JOIN unnest(CAST(? AS bigint[]), CAST(? AS bigint[])) AS courses (prescription_id, course_id)
                    ON courses.prescription_id = dose_events.prescription_id
                WHERE dose_events.status IN (?, ?, ?)
            ) AS counted
            WHERE taken_since = 0
            GROUP BY course_id
            SQL, [
            DoseEventStatus::Taken->value,
            '{'.implode(',', array_keys($courseOf)).'}',
            '{'.implode(',', $courseOf).'}',
            ...self::COUNTED,
        ]);

        $misses = [];

        foreach ($rows as $row) {
            $patientId = $patientOf[$row->course_id];
            $misses[$patientId] = max($misses[$patientId] ?? 0, (int) $row->misses);
        }

        return $misses;
    }

    /**
     * Сколько пациентов врача с сигналом и непринятым приёмом, который появился после того,
     * как врач последний раз открывал карточку.
     */
    public static function count(User $doctor): int
    {
        return count(self::unseenPatientIds($doctor));
    }

    /**
     * Пациенты врача, у которых есть сигнал и непринятый приём появился после того, как врач
     * последний раз открывал карточку. Они входят в счётчик у пункта меню «Пациенты».
     *
     * @return list<int>
     */
    public static function unseenPatientIds(User $doctor): array
    {
        return array_keys(self::raisedAmong($doctor, function (Builder $query): void {
            self::notTaken($query);
            self::unseen($query);
        }));
    }

    /**
     * Сигналы всех пациентов врача, у которых он есть: для очереди «Требуют внимания».
     *
     * @return array<int, AdherenceSignal> id пациента => сигнал
     */
    public static function raised(User $doctor): array
    {
        return self::raisedAmong($doctor, fn (Builder $query) => self::notTaken($query));
    }

    /**
     * Сигналы пациентов врача, у которых есть непринятый приём из условия: без него сигнала
     * не бывает, а доля и пропуски подряд считаются только для таких пациентов.
     *
     * @param  Closure(Builder): void  $notTaken
     * @return array<int, AdherenceSignal> id пациента => сигнал
     */
    private static function raisedAmong(User $doctor, Closure $notTaken): array
    {
        $candidates = CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->whereExists($notTaken)
            ->select('care_relationships.patient_id')
            ->selectSub(self::shareQuery('care_relationships.patient_id'), 'adherence_share')
            ->with('patient.patientProfile')
            ->get();

        $misses = self::consecutiveMisses($candidates->map(fn (CareRelationship $care): User => $care->patient));

        return $candidates
            ->mapWithKeys(fn (CareRelationship $care): array => [
                $care->patient_id => new AdherenceSignal($care->adherence_share, $misses[$care->patient_id] ?? 0),
            ])
            ->filter(fn (AdherenceSignal $signal): bool => $signal->isRaised())
            ->all();
    }

    /**
     * Непринятый приём регулярного назначения врача у пациента из внешнего запроса: не принят
     * или пропущен.
     */
    private static function notTaken(Builder $query): void
    {
        $query->select(DB::raw(1))
            ->from('dose_events')
            ->join('prescriptions', 'prescriptions.id', '=', 'dose_events.prescription_id')
            ->whereColumn('dose_events.patient_id', 'care_relationships.patient_id')
            ->where('prescriptions.source', PrescriptionSource::Doctor->value)
            ->whereIn('dose_events.status', [DoseEventStatus::Skipped->value, DoseEventStatus::Missed->value]);
    }

    /**
     * Непринятый приём, который врач ещё не видел: стал непринятым позже, чем врач последний раз
     * открывал карточку. «Не принял» — в момент отметки, пропущенный — когда закрылось окно приёма.
     */
    private static function unseen(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('care_relationships.adherence_seen_at')
            ->orWhereRaw(
                'CASE dose_events.status WHEN ? THEN dose_events.marked_at ELSE dose_events.window_closes_at END > care_relationships.adherence_seen_at',
                [DoseEventStatus::Skipped->value],
            ));
    }
}
