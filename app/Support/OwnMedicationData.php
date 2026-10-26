<?php

namespace App\Support;

use App\Enums\PrescriptionKind;
use App\Models\Drug;
use Carbon\CarbonImmutable;

/**
 * Проверенные данные формы препарата, который пациент добавляет сам.
 */
final readonly class OwnMedicationData
{
    /**
     * @param  Drug|null  $drug  препарат из справочника или списка препаратов от других врачей, null для «другого»
     * @param  string|null  $drugName  название «другого» препарата
     * @param  array<string, string>  $intakes  приёмы регулярного приёма в порядке дня:
     *                                          значение TimeOfDay => местное время «08:00»;
     *                                          у приёма «по необходимости» пусто
     * @param  string|null  $tradeName  торговое название препарата, по которому пациент его нашёл
     */
    public function __construct(
        public ?Drug $drug,
        public ?string $drugName,
        public string $dose,
        public PrescriptionKind $kind,
        public array $intakes,
        public ?int $maxDailyDoses,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public ?string $prescribedBy,
        public ?string $tradeName = null,
    ) {}
}
