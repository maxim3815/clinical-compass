<?php

namespace App\Support\Safety;

use App\Enums\Sex;
use App\Models\Diagnosis;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Данные пациента, над которыми работают правила: возраст, пол, текущие факты, действующие
 * диагнозы и всё, что пациент принимает сейчас или начнёт принимать.
 */
final readonly class PatientSafetyData
{
    /**
     * @param  array<string, Observation>  $facts  последний факт по коду вида
     * @param  array<string, ObservationType>  $types  виды фактов по коду
     * @param  Collection<int, Diagnosis>  $diagnoses  действующие диагнозы
     * @param  list<Medication>  $medications  действующие и будущие назначения
     */
    public function __construct(
        public ?int $age,
        public ?Sex $sex,
        public array $facts,
        public array $types,
        public Collection $diagnoses,
        public array $medications,
    ) {}

    /**
     * @param  int|null  $without  назначение, которое врач сейчас меняет: его заменит новая версия
     */
    public static function load(User $patient, ?int $without = null): self
    {
        $profile = $patient->patientProfile()->first();

        $facts = $patient->observations()
            ->with('type')
            ->orderBy('observed_on')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Observation $observation): string => $observation->type->code)
            ->all();

        $medications = array_values($patient->prescriptions()
            ->current($patient->localToday())
            ->when($without !== null, fn ($query) => $query->whereKeyNot($without))
            ->with('drug')
            ->orderBy('id')
            ->get()
            ->map(fn (Prescription $prescription): Medication => Medication::fromPrescription($prescription))
            ->all());

        return new self(
            age: $profile?->age(),
            sex: $profile?->sex,
            facts: $facts,
            types: ObservationType::query()->get()->keyBy('code')->all(),
            diagnoses: $patient->diagnoses()->active()->with('icd10')->orderBy('id')->get(),
            medications: $medications,
        );
    }

    /**
     * Все принимаемые препараты, кроме проверяемого. Если врач сейчас оформляет назначение,
     * оно тоже входит.
     *
     * @return list<Medication>
     */
    public function othersThan(Medication $target, ?Medication $candidate = null): array
    {
        $all = $candidate === null ? $this->medications : [...$this->medications, $candidate];

        return array_values(array_filter($all, fn (Medication $medication): bool => $medication !== $target));
    }
}
