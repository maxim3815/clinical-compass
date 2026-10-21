<?php

namespace App\Support\Safety;

use App\Enums\Sex;
use App\Models\Diagnosis;
use App\Support\Plural;

/**
 * Вычисление одного правила для одного препарата: функции языка условий отвечают отсюда
 * и запоминают, на каких данных пациента сработало правило.
 */
final class RuleEvaluation
{
    /**
     * Строки для врача: «СКФ 52 мл/мин/1,73 м² на 14.10.2026», «Принимает Гидроксизин (добавил пациент)».
     *
     * @var list<string>
     */
    public array $evidence = [];

    /**
     * Назначения, с которыми сочетание: по ним решение врача относится сразу к обеим сторонам.
     *
     * @var list<int>
     */
    public array $relatedPrescriptionIds = [];

    /**
     * Сочетание с назначением, которое врач сейчас оформляет.
     */
    public bool $withCandidate = false;

    /**
     * @param  list<Medication>  $others  что ещё принимает пациент
     */
    public function __construct(
        private readonly PatientSafetyData $data,
        private readonly Medication $target,
        private readonly array $others,
    ) {}

    /**
     * @throws MissingData
     */
    public function fact(string $code): bool|float|string
    {
        $type = $this->data->types[$code] ?? throw new MissingData($code);

        if ($type->female_only) {
            if ($this->data->sex === null) {
                throw new MissingData('пол');
            }

            if ($this->data->sex === Sex::Male) {
                return false;
            }
        }

        $fact = $this->data->facts[$code] ?? throw new MissingData($type->name);
        $this->note($fact->describe());

        return $fact->value();
    }

    /**
     * @throws MissingData
     */
    public function age(): int
    {
        $age = $this->data->age ?? throw new MissingData('дата рождения');
        $this->note('Возраст '.$age.' '.Plural::choose($age, ['год', 'года', 'лет']));

        return $age;
    }

    /**
     * @throws MissingData
     */
    public function diagnosis(string $code): bool
    {
        if ($this->data->diagnoses->isEmpty()) {
            throw new MissingData('диагноз');
        }

        $matches = $this->data->diagnoses->filter(fn (Diagnosis $diagnosis): bool => $diagnosis->matches($code));

        foreach ($matches as $diagnosis) {
            $this->note('Диагноз '.$diagnosis->icd10->label());
        }

        return $matches->isNotEmpty();
    }

    public function taking(string $drug): bool
    {
        return $this->takingAny(fn (Medication $medication): bool => $medication->drug?->code === $drug);
    }

    public function takingGroup(string $group): bool
    {
        return $this->takingAny(fn (Medication $medication): bool => in_array($group, $medication->drug->groups ?? [], true));
    }

    /**
     * @param  callable(Medication): bool  $matches
     */
    private function takingAny(callable $matches): bool
    {
        $found = false;

        foreach ($this->others as $medication) {
            // Тот же препарат по другому назначению — это не сочетание.
            if ($medication->isSameDrug($this->target) || ! $matches($medication)) {
                continue;
            }

            $found = true;
            $this->note('Принимает '.$medication->label());

            if ($medication->prescriptionId === null) {
                $this->withCandidate = true;
            } elseif (! in_array($medication->prescriptionId, $this->relatedPrescriptionIds, true)) {
                $this->relatedPrescriptionIds[] = $medication->prescriptionId;
            }
        }

        return $found;
    }

    private function note(string $line): void
    {
        if (! in_array($line, $this->evidence, true)) {
            $this->evidence[] = $line;
        }
    }
}
