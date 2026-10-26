<?php

namespace App\Support\Referrals;

use App\Enums\DoctorKind;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\Scale;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\Safety\ClinicalProfilePresenter;
use Carbon\CarbonImmutable;

/**
 * Направления к психиатру на экранах: карточка и список терапевта, очередь и страница психиатра,
 * блок в карточке принятого пациента, карточка на «Сегодня» у пациента.
 */
final class ReferralPresenter
{
    /**
     * Принятое направление пациент видит на «Сегодня» ещё неделю: кто теперь его ведёт.
     */
    public const int ACCEPTED_DAYS_FOR_PATIENT = 7;

    /**
     * Принятые направления остаются у терапевта в «Направлены» месяц.
     */
    public const int ACCEPTED_DAYS_FOR_THERAPIST = 30;

    /**
     * @return array<string, mixed>
     */
    public static function present(Referral $referral): array
    {
        $referral->loadMissing(['fromDoctor.doctorProfile', 'toDoctor.doctorProfile']);

        return [
            'id' => $referral->id,
            'category' => $referral->category->value,
            'suggested_category' => $referral->suggested_category?->value,
            'basis' => $referral->basis,
            'comment' => $referral->comment,
            'from' => self::doctor($referral->fromDoctor),
            'to' => self::doctor($referral->toDoctor),
            'status' => self::status($referral),
            'created_at' => DisplayTime::format($referral->created_at),
            'accepted_at' => $referral->accepted_at === null ? null : DisplayTime::formatDate($referral->accepted_at),
        ];
    }

    /**
     * Карточка терапевта: направление, которое ждёт психиатра, и последнее решение «не направлять».
     *
     * @return array{referral: array<string, mixed>|null, declined: array{at: string, comment: string|null, suggested_category: string|null}|null}
     */
    public static function forTherapistCard(User $therapist, User $patient): array
    {
        $open = Referral::query()->open()->where('patient_id', $patient->id)->first();

        $declined = AuditLog::query()
            ->where('action', 'referral.declined')
            ->where('patient_id', $patient->id)
            ->where('actor_id', $therapist->id)
            ->latest('id')
            ->first();

        return [
            'referral' => $open === null ? null : self::present($open),
            'declined' => $declined === null || $declined->created_at === null ? null : [
                'at' => DisplayTime::format($declined->created_at),
                'comment' => is_string($declined->after['comment'] ?? null) ? $declined->after['comment'] : null,
                'suggested_category' => is_string($declined->after['suggested_category'] ?? null) ? $declined->after['suggested_category'] : null,
            ],
        ];
    }

    /**
     * Список терапевта, «Направлены»: ждут психиатра и принятые за последний месяц, сначала новые.
     * Карточка открывается, только пока пациента ведёт терапевт.
     *
     * @return list<array<string, mixed>>
     */
    public static function sentBy(User $therapist): array
    {
        $since = CarbonImmutable::now()->subDays(self::ACCEPTED_DAYS_FOR_THERAPIST);

        return array_values(Referral::query()
            ->where('from_doctor_id', $therapist->id)
            ->whereNull('withdrawn_at')
            ->where(fn ($query) => $query->whereNull('accepted_at')->orWhere('accepted_at', '>=', $since))
            ->with(['patient.patientProfile', 'toDoctor'])
            ->latest('id')
            ->get()
            ->map(fn (Referral $referral): array => [
                'id' => $referral->id,
                'patient' => [
                    'id' => $referral->patient->id,
                    'name' => $referral->patient->name,
                    'age' => $referral->patient->patientProfile?->age(),
                ],
                'category' => $referral->category->value,
                'to' => $referral->toDoctor->name,
                'status' => self::status($referral),
                'created_at' => DisplayTime::format($referral->created_at),
                'accepted_at' => $referral->accepted_at === null ? null : DisplayTime::formatDate($referral->accepted_at),
            ])
            ->values()
            ->all());
    }

    /**
     * Очередь психиатра: направления, которые ждут его, сначала «обязательно», внутри — старые
     * первыми: их ждут дольше.
     *
     * @return list<array<string, mixed>>
     */
    public static function incoming(User $psychiatrist): array
    {
        $referrals = Referral::query()
            ->open()
            ->where('to_doctor_id', $psychiatrist->id)
            ->with(['patient.patientProfile', 'fromDoctor.doctorProfile'])
            ->orderBy('id')
            ->get();

        if ($referrals->isEmpty()) {
            return [];
        }

        $diagnoses = ClinicalProfilePresenter::mainDiagnoses($referrals->pluck('patient_id')->map(fn (mixed $id): int => (int) $id)->all());

        return array_values($referrals
            ->sortByDesc(fn (Referral $referral): int => $referral->category->rank())
            ->map(fn (Referral $referral): array => [
                'id' => $referral->patient->id,
                'name' => $referral->patient->name,
                'age' => $referral->patient->patientProfile?->age(),
                'diagnosis' => $diagnoses[$referral->patient_id] ?? null,
                'referral' => [
                    'id' => $referral->id,
                    'category' => $referral->category->value,
                    'from' => self::doctor($referral->fromDoctor),
                    'created_at' => DisplayTime::format($referral->created_at),
                    'summary' => self::summary($referral->basis),
                ],
            ])
            ->values()
            ->all());
    }

    /**
     * Пациенты с направлением, которое ждёт психиатра: для числа у «Пациентов».
     *
     * @return list<int>
     */
    public static function incomingPatientIds(User $doctor): array
    {
        return array_values(Referral::query()
            ->open()
            ->where('to_doctor_id', $doctor->id)
            ->pluck('patient_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Карточка у психиатра: по какому направлению пациент к нему пришёл.
     *
     * @return array<string, mixed>|null
     */
    public static function forPsychiatristCard(User $psychiatrist, User $patient): ?array
    {
        $referral = Referral::query()
            ->where('patient_id', $patient->id)
            ->where('to_doctor_id', $psychiatrist->id)
            ->whereNotNull('accepted_at')
            ->latest('accepted_at')
            ->first();

        return $referral === null ? null : self::present($referral);
    }

    /**
     * Карточка на «Сегодня»: к кому направили, мягкая категория и статус. Без основания и баллов.
     *
     * @return array{to: array{name: string, specialty: string|null}, category: string, status: string, accepted_at: string|null}|null
     */
    public static function forPatient(User $patient): ?array
    {
        $since = CarbonImmutable::now()->subDays(self::ACCEPTED_DAYS_FOR_PATIENT);

        $referral = Referral::query()
            ->where('patient_id', $patient->id)
            ->whereNull('withdrawn_at')
            ->where(fn ($query) => $query->whereNull('accepted_at')->orWhere('accepted_at', '>=', $since))
            ->with('toDoctor.doctorProfile')
            ->latest('id')
            ->first();

        if ($referral === null) {
            return null;
        }

        return [
            'to' => self::doctor($referral->toDoctor),
            'category' => $referral->category->patientLabel(),
            'status' => self::status($referral),
            'accepted_at' => $referral->accepted_at?->setTimezone($patient->localTimezone())->isoFormat('D MMMM'),
        ];
    }

    /**
     * Психиатры, к которым терапевт может направить пациента.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function psychiatrists(): array
    {
        return array_values(User::query()
            ->where('role', UserRole::Doctor)
            ->whereHas('doctorProfile', fn ($query) => $query->where('kind', DoctorKind::Psychiatrist))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $doctor): array => ['id' => $doctor->id, 'name' => $doctor->name])
            ->values()
            ->all());
    }

    /**
     * Основание одной строкой для очереди: «HADS: тревога 16, депрессия 12 · GAD-7 16 · PHQ-9,
     * вопрос 9: 2». Суммы подшкал одной шкалы идут вместе.
     *
     * @param  list<array<string, mixed>>  $basis
     */
    private static function summary(array $basis): string
    {
        $parts = [];
        $subscales = [];

        foreach ($basis as $match) {
            foreach ((array) ($match['evidence'] ?? []) as $evidence) {
                if (($evidence['type'] ?? null) === 'total' && isset($evidence['subscale'])) {
                    $key = 'scale:'.$evidence['scale'];
                    $parts[$key] ??= '';
                    $subscales[$evidence['scale']]["{$evidence['subscale']} {$evidence['total']}"] = true;

                    continue;
                }

                $part = match ($evidence['type'] ?? null) {
                    'total' => "{$evidence['name']} {$evidence['total']}",
                    'answer' => "{$evidence['name']}, вопрос {$evidence['item']}: {$evidence['score']}",
                    'fact' => mb_strtolower((string) $evidence['name']),
                    default => null,
                };

                if ($part !== null) {
                    $parts[$part] = $part;
                }
            }
        }

        foreach ($subscales as $scale => $values) {
            $name = Scale::query()->where('code', $scale)->value('name') ?? $scale;
            $parts["scale:{$scale}"] = "{$name}: ".implode(', ', array_keys($values));
        }

        return implode(' · ', array_values(array_filter($parts)));
    }

    private static function status(Referral $referral): string
    {
        return match (true) {
            $referral->accepted_at !== null => 'accepted',
            $referral->withdrawn_at !== null => 'withdrawn',
            default => 'open',
        };
    }

    /**
     * @return array{name: string, specialty: string|null}
     */
    private static function doctor(User $doctor): array
    {
        return [
            'name' => $doctor->name,
            'specialty' => $doctor->doctorProfile?->specialty,
        ];
    }
}
