<?php

namespace App\Support\Referrals;

use App\Enums\ScaleRater;
use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\Observation;
use App\Models\User;
use InvalidArgumentException;

/**
 * Данные пациента, по которым считается подсказка направления: последние заполнения шкал
 * пациента за 14 дней, шкалы врача, заполненные сегодня, и текущие факты клинического профиля.
 */
final readonly class ReferralData
{
    /**
     * Шкалы пациента о последних двух неделях: старше — уже не про нынешнее состояние.
     */
    public const int PATIENT_SCALE_DAYS = 14;

    /**
     * @param  array<string, Assessment>  $assessments  код шкалы => заполнение с загруженными шкалой и ответами
     * @param  array<string, Observation>  $facts  код вида факта => текущая запись с загруженным видом
     */
    public function __construct(
        public array $assessments,
        public array $facts,
    ) {}

    public static function load(User $patient): self
    {
        $today = $patient->localToday();

        $assessments = Assessment::query()
            ->where('patient_id', $patient->id)
            ->where('local_date', '>=', $today->subDays(self::PATIENT_SCALE_DAYS - 1)->toDateString())
            ->with(['scale', 'answers'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get()
            // Шкалу врача — только с этого приёма: заполненную сегодня.
            ->filter(fn (Assessment $assessment): bool => $assessment->scale->rater === ScaleRater::Patient || $assessment->local_date->eq($today))
            // Последнее заполнение каждой шкалы перекрывает предыдущие.
            ->keyBy(fn (Assessment $assessment): string => $assessment->scale->code)
            ->all();

        $facts = $patient->observations()
            ->with('type')
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Observation $observation): string => $observation->type->code)
            ->all();

        return new self($assessments, $facts);
    }

    public function has(string $scale): bool
    {
        return isset($this->assessments[$scale]);
    }

    public function assessment(string $scale): Assessment
    {
        return $this->assessments[$scale] ?? throw new InvalidArgumentException("Шкалы {$scale} среди заполнений нет.");
    }

    public function answer(string $scale, int $item): int
    {
        $answer = $this->assessment($scale)->answers->first(fn (AssessmentAnswer $answer): bool => (int) $answer->item === $item);

        if ($answer === null) {
            throw new InvalidArgumentException("В шкале {$scale} нет вопроса {$item}.");
        }

        return (int) $answer->score;
    }

    /**
     * Сумма шкалы или, с кодом подшкалы, сумма подшкалы (HADS, тревога).
     */
    public function total(string $scale, ?string $subscale = null): int
    {
        $assessment = $this->assessment($scale);

        if ($subscale === null) {
            return $assessment->total;
        }

        return $assessment->subscore($subscale)['total'] ?? throw new InvalidArgumentException("У шкалы {$scale} нет подшкалы {$subscale}.");
    }

    public function fact(string $code): bool|float|string|null
    {
        return ($this->facts[$code] ?? null)?->value();
    }

    public function factRecord(string $code): ?Observation
    {
        return $this->facts[$code] ?? null;
    }
}
