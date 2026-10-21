<?php

namespace App\Support\Safety;

use App\Models\Drug;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\User;

/**
 * Проверка назначения до сохранения: для блока в форме и для повторной проверки на сервере.
 */
final readonly class SafetyCheck
{
    public function __construct(private SafetyEngine $engine) {}

    /**
     * @param  Prescription|null  $replacing  назначение, которое врач меняет: новая версия заменит его
     */
    public function forCandidate(User $patient, Drug $drug, ?Prescription $replacing = null): SafetyReport
    {
        return $this->check(PatientSafetyData::load($patient, $replacing?->id), $drug, $this->decided($patient, $replacing));
    }

    /**
     * Проверка каждого препарата для выбора в форме нового назначения: данные пациента
     * загружаются один раз.
     *
     * @param  iterable<Drug>  $drugs
     * @return array<int, SafetyReport> id препарата => проверка
     */
    public function forCandidates(User $patient, iterable $drugs): array
    {
        $data = PatientSafetyData::load($patient);
        $decided = $this->decided($patient, null);
        $reports = [];

        foreach ($drugs as $drug) {
            $reports[$drug->id] = $this->check($data, $drug, $decided);
        }

        return $reports;
    }

    /**
     * @param  array<string, true>  $decided
     */
    private function check(PatientSafetyData $data, Drug $drug, array $decided): SafetyReport
    {
        $candidate = Medication::candidate($drug);
        $own = $this->engine->check($data, $candidate, $data->othersThan($candidate));

        // У других препаратов — только то, что появится из-за нового назначения.
        $caused = [];

        foreach ($data->medications as $medication) {
            if (! $this->engine->hasRules($medication)) {
                continue;
            }

            foreach ($this->engine->check($data, $medication, $data->othersThan($medication, $candidate))->results as $result) {
                if ($result->withCandidate) {
                    $caused[] = $result;
                }
            }
        }

        return new SafetyReport(
            candidate: $candidate,
            results: $own->results,
            caused: $caused,
            unchecked: $own->unchecked,
            unknownDrugs: array_values(array_unique(array_map(
                fn (Medication $medication): string => $medication->name,
                array_filter($data->medications, fn (Medication $medication): bool => $medication->drug === null),
            ))),
            hasRules: $this->engine->hasRules($candidate),
            decided: $decided,
        );
    }

    /**
     * Красные находки, по которым врач уже решил и которые останутся после сохранения: у меняемого
     * назначения они перейдут к новой версии (ключ «new»), у других препаратов останутся на месте.
     *
     * @return array<string, true>
     */
    private function decided(User $patient, ?Prescription $replacing): array
    {
        $decided = [];

        $findings = SafetyFinding::query()
            ->where('patient_id', $patient->id)
            ->open()
            ->whereNotNull('decided_at')
            ->get();

        foreach ($findings as $finding) {
            $prescriptionId = $finding->prescription_id === $replacing?->id ? null : $finding->prescription_id;
            $decided[RuleResult::keyOf($finding->rule_id, $prescriptionId)] = true;
        }

        return $decided;
    }
}
