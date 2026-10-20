<?php

namespace App\Support\Adherence;

use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Models\Prescription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Курс — назначение со всеми его версиями (previous_id). Изменение дозы курс продолжает,
 * отмена и новое назначение того же препарата начинают новый курс. Даты — местные даты
 * пациента, как у назначений: полночь UTC.
 */
final class Course
{
    /**
     * @param  non-empty-list<Prescription>  $versions  Версии по порядку, первая начинает курс.
     */
    private function __construct(
        public readonly array $versions,
        public readonly CarbonImmutable $start,
        public readonly ?CarbonImmutable $end,
    ) {}

    /**
     * Курсы из версий назначений пациента.
     *
     * @param  Collection<int, Prescription>  $prescriptions
     * @return array<int, self>
     */
    public static function fromPrescriptions(Collection $prescriptions, string $timezone): array
    {
        $next = $prescriptions
            ->filter(fn (Prescription $prescription): bool => $prescription->previous_id !== null)
            ->keyBy('previous_id');
        $ids = $prescriptions->pluck('id')->flip();

        return $prescriptions
            ->filter(fn (Prescription $prescription): bool => $prescription->previous_id === null || ! $ids->has($prescription->previous_id))
            ->sortBy('id')
            ->map(function (Prescription $first) use ($next, $timezone): self {
                $versions = [$first];

                while (($following = $next->get(end($versions)->id)) !== null) {
                    $versions[] = $following;
                }

                return new self($versions, $first->starts_on, self::endOf(end($versions), $timezone));
            })
            ->values()
            ->all();
    }

    /**
     * Действующая или последняя версия.
     */
    public function last(): Prescription
    {
        return $this->versions[array_key_last($this->versions)];
    }

    public function kind(): PrescriptionKind
    {
        return $this->versions[0]->kind;
    }

    public function source(): PrescriptionSource
    {
        return $this->versions[0]->source;
    }

    /**
     * Курс начался: дата начала прошла, и его не отменили до начала.
     */
    public function hasStarted(CarbonImmutable $today): bool
    {
        return $this->start->lte($today) && ($this->end === null || $this->end->gte($this->start));
    }

    /**
     * Курс закончился: последнюю версию отменили или прекратили, или прошла дата окончания.
     */
    public function isFinished(CarbonImmutable $today): bool
    {
        return $this->last()->closed_at !== null || ($this->end !== null && $this->end->lt($today));
    }

    /**
     * Последний прошедший день курса: сегодня или дата окончания, если курс закончился.
     */
    public function lastDay(CarbonImmutable $today): CarbonImmutable
    {
        return $this->end !== null && $this->end->lt($today) ? $this->end : $today;
    }

    /**
     * День курса: день начала — первый.
     */
    public function dayNumber(CarbonImmutable $date): int
    {
        return (int) $this->start->diffInDays($date) + 1;
    }

    /**
     * Версия, которая действовала в этот день. В день изменения — новая.
     */
    public function versionOn(CarbonImmutable $date): Prescription
    {
        $version = $this->versions[0];

        foreach ($this->versions as $candidate) {
            if ($candidate->starts_on->lte($date)) {
                $version = $candidate;
            }
        }

        return $version;
    }

    /**
     * id всех версий курса.
     *
     * @return list<int>
     */
    public function versionIds(): array
    {
        return array_map(fn (Prescription $version): int => $version->id, $this->versions);
    }

    /**
     * Конец курса: местная дата отмены или прекращения, иначе дата окончания (может быть в будущем или не задана).
     */
    private static function endOf(Prescription $last, string $timezone): ?CarbonImmutable
    {
        if ($last->closed_at === null) {
            return $last->ends_on;
        }

        $closedOn = CarbonImmutable::parse($last->closed_at->setTimezone($timezone)->toDateString(), 'UTC');

        return $last->ends_on !== null && $last->ends_on->lt($closedOn) ? $last->ends_on : $closedOn;
    }
}
