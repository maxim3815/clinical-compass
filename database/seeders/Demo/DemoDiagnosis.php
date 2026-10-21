<?php

namespace Database\Seeders\Demo;

/**
 * Диагноз демо-пациента по МКБ-10. Без даты его ставят при заведении пациента, с датой —
 * вечером в этот день, считая назад от сегодня.
 */
final readonly class DemoDiagnosis
{
    private function __construct(
        public string $code,
        public bool $main,
        public ?int $daysAgo = null,
        public ?int $resolvedDaysAgo = null,
    ) {}

    public static function main(string $code, ?int $daysAgo = null): self
    {
        return new self($code, true, $daysAgo);
    }

    public static function concomitant(string $code): self
    {
        return new self($code, false);
    }

    /**
     * Врач снял диагноз вечером в этот день, раньше, чем поставил новые диагнозы того же вечера.
     */
    public function resolved(int $daysAgo): self
    {
        return new self($this->code, $this->main, $this->daysAgo, $daysAgo);
    }
}
