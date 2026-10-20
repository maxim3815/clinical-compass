<?php

namespace App\Support\Adherence;

/**
 * Сигнал о приверженности по регулярным назначениям врача: доля принятых за последние дни
 * и непринятые подряд в конце действующего курса. Посчитан в AdherenceSignals, пороги лежат
 * в config/medications.php.
 */
final class AdherenceSignal
{
    /**
     * @param  int|null  $share  Доля принятых за AdherenceSignals::DAYS дней, без учтённых приёмов null.
     * @param  int  $consecutiveMisses  Больше всего непринятых подряд в конце одного действующего курса.
     */
    public function __construct(
        public readonly ?int $share,
        public readonly int $consecutiveMisses,
    ) {}

    public function isLowShare(): bool
    {
        return $this->share !== null && $this->share < config()->integer('medications.adherence.signal_share');
    }

    public function hasConsecutiveMisses(): bool
    {
        return $this->consecutiveMisses >= config()->integer('medications.adherence.signal_consecutive');
    }

    public function isRaised(): bool
    {
        return $this->isLowShare() || $this->hasConsecutiveMisses();
    }

    /**
     * @return array{share: int|null, low_share: bool, consecutive_misses: int|null, raised: bool}
     */
    public function toArray(): array
    {
        return [
            'share' => $this->share,
            'low_share' => $this->isLowShare(),
            // Число пропусков врач видит, только когда сработало это условие.
            'consecutive_misses' => $this->hasConsecutiveMisses() ? $this->consecutiveMisses : null,
            'raised' => $this->isRaised(),
        ];
    }
}
