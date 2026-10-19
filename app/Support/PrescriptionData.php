<?php

namespace App\Support;

use App\Enums\PrescriptionKind;
use App\Models\DrugStrength;
use Carbon\CarbonImmutable;

/**
 * Проверенные данные формы назначения.
 */
final readonly class PrescriptionData
{
    /**
     * @param  array<string, string>  $intakes  приёмы регулярного назначения в порядке дня:
     *                                          значение TimeOfDay => таблеток («0.5», «1.0»);
     *                                          у приёма «по необходимости» пусто
     * @param  string|null  $tablets  таблеток за раз у приёма «по необходимости»
     */
    public function __construct(
        public DrugStrength $strength,
        public PrescriptionKind $kind,
        public array $intakes,
        public ?string $tablets,
        public ?int $maxDailyDoses,
        public ?string $instruction,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
    ) {}

    /**
     * Количество таблеток в том виде, в каком его возвращает модель: «0.5», «1.0».
     */
    public static function tablets(string|int|float $tablets): string
    {
        return number_format((float) $tablets, 1, '.', '');
    }
}
