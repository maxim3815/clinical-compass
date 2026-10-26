<?php

namespace App\Support\Safety;

use App\Enums\PrescriptionSource;
use App\Models\Drug;
use App\Models\Prescription;

/**
 * Препарат, который пациент принимает или начнёт принимать: действующее или будущее назначение
 * врача, свой препарат пациента или назначение, которое врач сейчас оформляет (без id).
 */
final readonly class Medication
{
    public function __construct(
        public ?int $prescriptionId,
        public ?Drug $drug,
        public string $name,
        public PrescriptionSource $source,
        // Оформляемое назначение заменит действующее: врач его изменяет.
        public bool $changing = false,
        // Торговое название, по которому пациент нашёл свой препарат: «Кордарон».
        public ?string $tradeName = null,
    ) {}

    public static function fromPrescription(Prescription $prescription): self
    {
        return new self(
            $prescription->id,
            $prescription->drug,
            $prescription->drug->name ?? (string) $prescription->drug_name,
            $prescription->source,
            tradeName: $prescription->trade_name,
        );
    }

    /**
     * Назначение врача, которое ещё не сохранено: новое или новая версия изменяемого.
     */
    public static function candidate(Drug $drug, bool $changing = false): self
    {
        return new self(null, $drug, $drug->name, PrescriptionSource::Doctor, $changing);
    }

    public function isSameDrug(self $other): bool
    {
        return $this->drug !== null && $this->drug->id === $other->drug?->id;
    }

    /**
     * Название для врача: «Амиодарон (Кордарон)».
     */
    public function displayName(): string
    {
        return $this->tradeName === null ? $this->name : "{$this->name} ({$this->tradeName})";
    }

    /**
     * «Эсциталопрам (добавил пациент)», «Амиодарон (Кордарон, добавил пациент)», «Гидроксизин (новое назначение)»,
     * «Авиандр (изменение назначения)».
     */
    public function label(): string
    {
        return match (true) {
            $this->prescriptionId === null && $this->changing => $this->name.' (изменение назначения)',
            $this->prescriptionId === null => $this->name.' (новое назначение)',
            $this->source === PrescriptionSource::Patient && $this->tradeName !== null => "{$this->name} ({$this->tradeName}, добавил пациент)",
            $this->source === PrescriptionSource::Patient => $this->name.' (добавил пациент)',
            default => $this->name,
        };
    }
}
