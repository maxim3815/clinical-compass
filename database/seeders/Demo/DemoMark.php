<?php

namespace Database\Seeders\Demo;

use App\Enums\DoseSkipReason;

/**
 * Что демо-пациент сделал с плановым приёмом: отметил «Принял» или «Не принял» через сколько-то
 * минут после планового времени или не отметил совсем, и тогда приём станет пропущенным.
 */
final readonly class DemoMark
{
    private function __construct(
        public ?int $afterMinutes,
        public ?DoseSkipReason $skipReason = null,
        public ?string $skipComment = null,
    ) {}

    public static function onTime(int $minutes = 5): self
    {
        return new self($minutes);
    }

    /**
     * Позже интервала «вовремя», но пока окно приёма открыто.
     */
    public static function late(int $minutes = 90): self
    {
        return new self($minutes);
    }

    public static function skipped(DoseSkipReason $reason, ?string $comment = null, int $minutes = 60): self
    {
        return new self($minutes, $reason, $comment);
    }

    public static function missed(): self
    {
        return new self(null);
    }
}
