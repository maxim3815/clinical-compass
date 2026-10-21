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
    ) {}

    public static function fromPrescription(Prescription $prescription): self
    {
        return new self($prescription->id, $prescription->drug, $prescription->drugName(), $prescription->source);
    }

    /**
     * Назначение врача, которое ещё не сохранено.
     */
    public static function candidate(Drug $drug): self
    {
        return new self(null, $drug, $drug->name, PrescriptionSource::Doctor);
    }

    public function isSameDrug(self $other): bool
    {
        return $this->drug !== null && $this->drug->id === $other->drug?->id;
    }

    /**
     * «Эсциталопрам (добавил пациент)», «Гидроксизин (новое назначение)».
     */
    public function label(): string
    {
        return match (true) {
            $this->prescriptionId === null => $this->name.' (новое назначение)',
            $this->source === PrescriptionSource::Patient => $this->name.' (добавил пациент)',
            default => $this->name,
        };
    }
}
